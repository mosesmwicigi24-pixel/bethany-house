<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\CurrencyPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A WhatsApp order carries the hub's price for the currency it is billed in.
 *
 * The bug this pins: Neema quotes from the KES catalogue, while the order's
 * currency is derived from the customer's country. Nothing reconciled the two,
 * so a KES 4,500 tray sold to a USD customer became "USD 4,500" — the label
 * moved, the number did not, and the customer was billed ~130x. The rule now:
 * the hub prices agent-taken orders, in the order's own currency, or the order
 * is refused.
 */
class WhatsAppOrderCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CurrencyPricing::forget();
    }

    // ── World ────────────────────────────────────────────────────────────────

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        // The till refuses a caller assigned to no outlet at all — assign the
        // fixture outlet so these stay tests about currency, not setup.
        $user->outlets()->sync([$this->outlet()->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        foreach (['orders.view', 'orders.edit'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A currency row. Base-relative rate; the schema's default is 1.0. */
    private function currency(string $code, float $rate = 1.0, bool $isBase = false): void
    {
        DB::table('currencies')->insert([
            'code' => $code, 'name' => $code, 'symbol' => $code,
            'exchange_rate' => $rate, 'is_base' => $isBase, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CurrencyPricing::forget();
    }

    /** A country whose customers are billed in $currency. */
    private function country(string $code, string $currency): void
    {
        DB::table('countries')->insert([
            'code' => $code, 'name' => $code, 'default_currency_code' => $currency,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A stocked product with the given price rows, e.g. ['KES' => 4500]. */
    private function product(array $prices, int $stock = 25): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);

        foreach ($prices as $code => $amount) {
            ProductPrice::create([
                'product_id'         => $product->id,
                'product_variant_id' => null,
                'currency_code'      => $code,
                'regular_price'      => $amount,
            ]);
        }

        InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => null,
            'outlet_id'          => $this->outlet()->id,
            'quantity_on_hand'   => $stock,
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);

        return $product;
    }

    private ?Outlet $outlet = null;

    private function outlet(): Outlet
    {
        return $this->outlet ??= Outlet::factory()->create([
            'sales_channel' => 'whatsapp',
            'country_code'  => 'KE',
        ]);
    }

    /** The call Neema makes: a WhatsApp order, priced by the agent in KES. */
    private function whatsappOrder(Product $product, string $countryCode, float $agentPrice = 4500)
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'             => $this->outlet()->id,
            'channel'               => 'whatsapp',
            'customer_country_code' => $countryCode,
            'items'                 => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'unit_price' => $agentPrice,
            ]],
        ]);
    }

    // ── The reported bug ─────────────────────────────────────────────────────

    public function test_a_usd_whatsapp_order_bills_the_hubs_usd_price_not_the_kes_number(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);
        $this->country('NG', 'USD');

        // The hub sells this tray for KES 4,500 or USD 35.
        $product = $this->product(['KES' => 4500, 'USD' => 35]);

        $res = $this->whatsappOrder($product, 'NG', agentPrice: 4500)->assertSuccessful();

        $this->assertSame('USD', $res->json('currency_code'));

        $order = Order::with('items')->find($res->json('order_id'));
        $this->assertSame('USD', $order->currency_code);
        // 35, not the 4,500 the agent quoted from the KES shelf.
        $this->assertEqualsWithDelta(35.0, (float) $order->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(35.0, (float) $order->total_amount, 0.01);
    }

    public function test_a_zambian_whatsapp_order_bills_the_hubs_kwacha_price(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('ZMW', 0.2);
        $this->country('ZM', 'ZMW');

        $product = $this->product(['KES' => 4500, 'ZMW' => 900]);

        $res = $this->whatsappOrder($product, 'ZM', agentPrice: 4500)->assertSuccessful();

        $order = Order::with('items')->find($res->json('order_id'));
        $this->assertSame('ZMW', $order->currency_code);
        // The kwacha row as the shop typed it — not 4,500, and not the
        // 900 the rate happens to agree with here by construction.
        $this->assertEqualsWithDelta(900.0, (float) $order->items->first()->unit_price, 0.01);
    }

    public function test_without_a_currency_row_the_base_price_is_converted_at_the_configured_rate(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);
        $this->country('NG', 'USD');

        $product = $this->product(['KES' => 4500]);   // no USD row

        $res = $this->whatsappOrder($product, 'NG')->assertSuccessful();

        $order = Order::with('items')->find($res->json('order_id'));
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(34.65, (float) $order->items->first()->unit_price, 0.01);
    }

    public function test_an_unconfigured_rate_refuses_the_order_instead_of_relabelling_kes(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD');            // schema default 1.0 — nobody set a rate
        $this->country('NG', 'USD');

        $product = $this->product(['KES' => 4500]);

        $res = $this->whatsappOrder($product, 'NG')->assertStatus(422);

        $this->assertStringContainsString('USD', $res->json('message'));
        $this->assertStringContainsString('exchange rate', $res->json('message'));
        // Nothing was written, and the stock is still on the shelf.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) InventoryItem::where('product_id', $product->id)->value('quantity_reserved'));
    }

    public function test_a_pos_sale_keeps_the_cashiers_price(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);

        $product = $this->product(['KES' => 4500]);

        // No channel → 'pos'. The cashier knocked 500 off at the till.
        $res = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet()->id,
            'items'     => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'unit_price' => 4000,
            ]],
        ])->assertSuccessful();

        $order = Order::with('items')->find($res->json('order_id'));
        $this->assertEqualsWithDelta(4000.0, (float) $order->items->first()->unit_price, 0.01);
    }

    // ── The Set Country / Currency button ────────────────────────────────────

    public function test_switching_an_order_to_usd_reprices_from_the_hubs_usd_row(): void
    {
        $this->editor();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);
        $this->country('NG', 'USD');

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->kesOrderFor($product, 4500);

        $res = $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", [
            'country_code' => 'NG',
        ])->assertSuccessful();

        $this->assertSame('USD', $res->json('currency_code'));
        $this->assertEqualsWithDelta(35.0, (float) $res->json('new_total'), 0.01);
        $this->assertEqualsWithDelta(35.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_switching_currency_is_refused_when_the_hub_cannot_price_it(): void
    {
        $this->editor();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD');            // unconfigured 1.0 again
        $this->country('NG', 'USD');

        $product = $this->product(['KES' => 4500]);
        $order   = $this->kesOrderFor($product, 4500);

        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", [
            'country_code' => 'NG',
        ])->assertStatus(422);

        // The order is exactly as it was — no half-applied relabelling.
        $order->refresh();
        $this->assertSame('KES', $order->currency_code);
        $this->assertEqualsWithDelta(4500.0, (float) $order->total_amount, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $order->items->first()->unit_price, 0.01);
    }

    private function kesOrderFor(Product $product, float $unitPrice): Order
    {
        $order = Order::factory()->create([
            'order_type'     => 'whatsapp',
            'outlet_id'      => $this->outlet()->id,
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'subtotal'       => $unitPrice,
            'total_amount'   => $unitPrice,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'product_name' => 'Wooden tray',
            'sku'          => $product->sku,
            'quantity'     => 1,
            'unit_price'   => $unitPrice,
            'total_price'  => $unitPrice,
        ]);

        return $order->load('items');
    }

    // ── What the POS shows ───────────────────────────────────────────────────

    public function test_the_pos_marks_a_product_it_cannot_price_in_the_asked_currency(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD');            // no real rate

        $product = $this->product(['KES' => 4500]);

        $res = $this->getJson('/api/v1/admin/pos/products?outlet_id=' . $this->outlet()->id . '&currency=USD')
            ->assertSuccessful();

        $variant = collect($res->json('data'))
            ->firstWhere('id', $product->id)['variants'][0];

        // Not "USD 4500" — no price at all, so nothing can be sold at the wrong one.
        $this->assertTrue($variant['price_unavailable']);
        $this->assertNull($variant['price']);
    }

    public function test_the_pos_quotes_the_hubs_own_row_for_the_asked_currency(): void
    {
        $this->clerk();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);

        $res = $this->getJson('/api/v1/admin/pos/products?outlet_id=' . $this->outlet()->id . '&currency=USD')
            ->assertSuccessful();

        $variant = collect($res->json('data'))
            ->firstWhere('id', $product->id)['variants'][0];

        $this->assertFalse($variant['price_unavailable']);
        // The typed USD row wins over converting 4,500 at the rate (34.65).
        $this->assertEqualsWithDelta(35.0, (float) $variant['price'], 0.01);
    }

    // ── The rule itself ──────────────────────────────────────────────────────

    public function test_a_non_base_row_at_exactly_one_is_not_a_rate(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD');            // the schema default

        $this->assertFalse(CurrencyPricing::hasRate('USD'));
        $this->assertNull(CurrencyPricing::convert(4500, 'KES', 'USD'));
    }

    public function test_a_deliberate_rate_converts(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $this->assertTrue(CurrencyPricing::hasRate('USD'));
        $this->assertEqualsWithDelta(34.65, CurrencyPricing::convert(4500, 'KES', 'USD'), 0.01);
        $this->assertEqualsWithDelta(4500.0, CurrencyPricing::convert(34.65, 'USD', 'KES'), 1.0);
    }
}
