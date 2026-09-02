<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The hub must charge what Neema quoted.
 *
 * Neema reads her prices off /api/v1/products, taking the sale price where
 * there is one. The hub's agent order path took `regular_price` flat. So she
 * quoted the Preaching Gown at 18,000 and the hub billed 20,000 — on 60
 * products, every one of them live.
 *
 * The fix publishes ONE number, `effective_price`, computed on the server and
 * used by both sides, so the quote and the charge cannot be two calculations
 * that drift apart. The line still carries the LIST price with the saving shown
 * as a discount, exactly as the storefront does, so the receipt says what the
 * item normally costs and the website and the chat agree.
 */
class AgentOrderChargesTheAdvertisedPriceTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\CurrencyPricing::forget();
        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1,
             'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        $user->outlets()->sync([$this->outlet->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return $user;
    }

    /** A Preaching Gown: 20,000 list, 18,000 selling. */
    private function gown(float $regular = 20000, ?float $sale = 18000, array $window = []): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(array_merge([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $regular, 'sale_price' => $sale,
        ], $window));
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 20,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function push(Product $product, int $qty = 1, array $extra = [])
    {
        $this->agent();

        return $this->postJson('/api/v1/admin/pos/pending-order', array_merge([
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                // Neema sends her quoted figure; the hub prices it itself.
                'unit_price' => 18000,
            ]],
        ], $extra));
    }

    public function test_the_order_totals_the_selling_price_not_the_list_price(): void
    {
        $order = Order::find($this->push($this->gown())->assertStatus(201)->json('order_id'));

        $this->assertSame(18000.0, (float) $order->total_amount,
            'she quoted 18,000 — the hub used to bill 20,000');
    }

    public function test_the_line_keeps_the_list_price_and_shows_the_saving(): void
    {
        // Same shape as a website order, so the receipt can say "was 20,000".
        $order = Order::find($this->push($this->gown())->assertStatus(201)->json('order_id'));
        $line  = $order->items()->first();

        $this->assertSame(20000.0, (float) $line->unit_price);
        $this->assertSame(2000.0, (float) $line->discount_amount);
    }

    public function test_the_saving_multiplies_by_quantity(): void
    {
        $order = Order::find($this->push($this->gown(), qty: 3)->assertStatus(201)->json('order_id'));

        $this->assertSame(54000.0, (float) $order->total_amount);
        $this->assertSame(6000.0, (float) $order->items()->first()->discount_amount);
    }

    public function test_a_product_with_no_sale_price_is_unchanged(): void
    {
        $order = Order::find($this->push($this->gown(sale: null))->assertStatus(201)->json('order_id'));

        $this->assertSame(20000.0, (float) $order->total_amount);
        $this->assertSame(0.0, (float) $order->items()->first()->discount_amount);
    }

    public function test_a_sale_whose_window_has_closed_is_not_honoured(): void
    {
        // The window is the shop's own statement about when the price applies.
        $product = $this->gown(window: [
            'sale_start_date' => now()->subDays(30),
            'sale_end_date'   => now()->subDays(2),
        ]);

        $order = Order::find($this->push($product)->assertStatus(201)->json('order_id'));

        $this->assertSame(20000.0, (float) $order->total_amount);
    }

    public function test_a_sale_whose_window_is_open_is_honoured(): void
    {
        $product = $this->gown(window: [
            'sale_start_date' => now()->subDay(),
            'sale_end_date'   => now()->addDays(5),
        ]);

        $order = Order::find($this->push($product)->assertStatus(201)->json('order_id'));

        $this->assertSame(18000.0, (float) $order->total_amount);
    }

    /**
     * The catalogue saving is the shop's own price, not clerk discretion, so it
     * must not be measured against the 5% manual-discount ceiling — a 10% sale
     * would otherwise 403 every order of that product.
     */
    public function test_a_deep_catalogue_saving_is_not_refused_by_the_discount_ceiling(): void
    {
        $product = $this->gown(regular: 20000, sale: 10000);   // 50% off

        $order = Order::find($this->push($product)->assertStatus(201)->json('order_id'));

        $this->assertSame(10000.0, (float) $order->total_amount);
    }

    /** But a discount the CALLER asks for is still policed as before. */
    public function test_a_caller_supplied_discount_still_hits_the_ceiling(): void
    {
        $product = $this->gown(sale: null);

        $this->push($product, extra: ['items' => [[
            'product_id'     => $product->id,
            'quantity'       => 1,
            'unit_price'     => 20000,
            'discount_type'  => 'percent',
            'discount_value' => 40,
        ]]])->assertStatus(403);
    }

    /** ── the campaign a customer was actually promised ──────────────────── */

    public function test_the_agent_can_carry_a_campaign_discount_the_till_could_not(): void
    {
        // 10% is twice the cashier ceiling. Refusing it is what forced the
        // discount to travel as a note a human had to apply by hand — while the
        // customer could already pay the undiscounted link.
        $product = $this->gown(sale: null);
        $agent   = $this->agent();
        $agent->givePermissionTo(Permission::findOrCreate('pos.discount', 'sanctum'));
        $agent->givePermissionTo(Permission::findOrCreate('pos.discount_campaign', 'sanctum'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000,
                'discount_type' => 'percent', 'discount_value' => 10,
            ]],
        ])->assertStatus(201);

        $this->assertSame(18000.0, (float) Order::find($res->json('order_id'))->total_amount,
            'the customer pays the price they were quoted, at the moment they were quoted it');
    }

    public function test_the_campaign_ceiling_still_bounds_the_agent(): void
    {
        $product = $this->gown(sale: null);
        $agent   = $this->agent();
        $agent->givePermissionTo(Permission::findOrCreate('pos.discount', 'sanctum'));
        $agent->givePermissionTo(Permission::findOrCreate('pos.discount_campaign', 'sanctum'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        config(['pos.agent_discount_cap_percent' => 70.0]);

        $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000,
                'discount_type' => 'percent', 'discount_value' => 85,
            ]],
        ])->assertStatus(403);
    }

    public function test_a_clerk_cannot_reach_the_agent_ceiling_by_posting_a_chat_channel(): void
    {
        // `channel` is caller-supplied. Keying the pass-through off it instead
        // of a capability would have handed every till the agent's ceiling.
        $product = $this->gown(sale: null);
        $clerk   = $this->agent();
        $clerk->givePermissionTo(Permission::findOrCreate('pos.discount', 'sanctum'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'         => $this->outlet->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000,
                'discount_type' => 'percent', 'discount_value' => 10,
            ]],
        ])->assertStatus(403);
    }

    public function test_the_feed_publishes_the_number_the_order_will_charge(): void
    {
        // The guarantee: one computation, read by both sides.
        $product = $this->gown();
        $price   = $product->prices()->first();

        $this->assertSame(18000.0, $price->effective_price);
        $this->assertSame(
            $price->effective_price,
            (float) Order::find($this->push($product)->assertStatus(201)->json('order_id'))->total_amount,
        );
    }
}
