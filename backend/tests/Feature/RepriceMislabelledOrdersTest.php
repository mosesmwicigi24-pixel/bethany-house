<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\CurrencyPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backfill for orders already written with another currency's numbers.
 *
 * It is deliberately timid: it corrects only what it can prove — a stored
 * price that is, to the cent, one of that product's OTHER currency rows — and
 * it never rewrites an order that has taken money.
 */
class RepriceMislabelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CurrencyPricing::forget();
    }

    private function currency(string $code, float $rate = 1.0, bool $isBase = false): void
    {
        DB::table('currencies')->insert([
            'code' => $code, 'name' => $code, 'symbol' => $code,
            'exchange_rate' => $rate, 'is_base' => $isBase, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CurrencyPricing::forget();
    }

    /** @param array<string,float> $prices */
    private function product(array $prices): Product
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

        return $product;
    }

    /** An order billing $unitPrice in $currency for $product. */
    private function order(Product $product, string $currency, float $unitPrice, array $overrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'order_type'     => 'whatsapp',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => $currency,
            'subtotal'       => $unitPrice,
            'total_amount'   => $unitPrice,
        ], $overrides));

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

    // ── The repair ───────────────────────────────────────────────────────────

    public function test_a_dry_run_reports_the_correction_and_writes_nothing(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 4500);   // the KES figure, billed as USD

        $this->artisan('orders:reprice-currency')
            ->expectsOutputToContain('Would reprice')
            ->assertSuccessful();

        $order->refresh();
        $this->assertEqualsWithDelta(4500.0, (float) $order->total_amount, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $order->items->first()->unit_price, 0.01);
    }

    public function test_apply_rewrites_the_line_and_the_order_total(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 4500);

        $this->artisan('orders:reprice-currency --apply')->assertSuccessful();

        $order->refresh()->load('items');
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(35.0, (float) $order->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(35.0, (float) $order->total_amount, 0.01);

        // The correction is on the audit trail, with what it moved from and to.
        $entry = DB::table('activity_log')->where('event', 'order_currency_repriced')->first();
        $this->assertNotNull($entry);
        $props = json_decode($entry->properties, true);
        $this->assertEqualsWithDelta(4500.0, (float) $props['old_total'], 0.01);
        $this->assertEqualsWithDelta(35.0, (float) $props['new_total'], 0.01);
    }

    public function test_a_kwacha_order_is_corrected_to_the_kwacha_row(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('ZMW', 0.2);

        $product = $this->product(['KES' => 4500, 'ZMW' => 900]);
        $order   = $this->order($product, 'ZMW', 4500);

        $this->artisan('orders:reprice-currency --apply')->assertSuccessful();

        $this->assertEqualsWithDelta(900.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_a_correct_order_is_left_alone(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 35);

        $this->artisan('orders:reprice-currency --apply')
            ->expectsOutputToContain('No mislabelled prices found')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(35.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_a_hand_set_price_is_not_touched(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        // 30 matches no price row — a negotiated figure, not a mislabel.
        $order   = $this->order($product, 'USD', 30);

        $this->artisan('orders:reprice-currency --apply')->assertSuccessful();

        $this->assertEqualsWithDelta(30.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_a_paid_order_is_reported_but_never_rewritten(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 4500, ['payment_status' => 'paid']);

        $this->artisan('orders:reprice-currency --apply')
            ->expectsOutputToContain('Money already taken')
            ->assertSuccessful();

        // Untouched: the cash collected and the order must still agree.
        $this->assertEqualsWithDelta(4500.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_a_cancelled_order_is_out_of_scope(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 4500, ['status' => 'cancelled']);

        $this->artisan('orders:reprice-currency --apply')
            ->expectsOutputToContain('No mislabelled prices found')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(4500.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_an_unpriceable_order_is_reported_not_guessed(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD');            // schema default 1.0 — no real rate

        $product = $this->product(['KES' => 4500]);
        $order   = $this->order($product, 'USD', 4500);

        $this->artisan('orders:reprice-currency --apply')
            ->expectsOutputToContain('Cannot fix')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(4500.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_it_can_be_scoped_to_one_order(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $mine    = $this->order($product, 'USD', 4500);
        $other   = $this->order($product, 'USD', 4500);

        $this->artisan("orders:reprice-currency --apply --order={$mine->id}")->assertSuccessful();

        $this->assertEqualsWithDelta(35.0, (float) $mine->fresh()->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $other->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_it_can_be_scoped_to_the_whatsapp_channel(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $outlet  = Outlet::factory()->create(['sales_channel' => 'pos']);
        $product = $this->product(['KES' => 4500, 'USD' => 35]);

        $wa  = $this->order($product, 'USD', 4500);                                        // order_type whatsapp
        $pos = $this->order($product, 'USD', 4500, ['order_type' => 'pos', 'outlet_id' => $outlet->id]);

        $this->artisan('orders:reprice-currency --apply --channel=whatsapp')->assertSuccessful();

        $this->assertEqualsWithDelta(35.0, (float) $wa->fresh()->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $pos->fresh()->items->first()->unit_price, 0.01);
    }

    public function test_tax_is_re_derived_from_the_product_not_scaled(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $order   = $this->order($product, 'USD', 4500);

        // A tax figure carried over from the KES numbers.
        DB::table('order_items')->where('order_id', $order->id)->update(['tax_amount' => 620.69]);

        $this->artisan('orders:reprice-currency --apply')->assertSuccessful();

        $tax = (float) $order->fresh()->items->first()->tax_amount;
        $this->assertLessThan(35.0, $tax, 'line tax must be recomputed against the corrected price');
    }

    /** An untracked line (no inventory row) still reprices — this is a money fix, not a stock one. */
    public function test_stock_is_not_disturbed(): void
    {
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);

        $outlet  = Outlet::factory()->create();
        $product = $this->product(['KES' => 4500, 'USD' => 35]);
        $inv     = InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => null,
            'outlet_id'          => $outlet->id,
            'quantity_on_hand'   => 10,
            'quantity_reserved'  => 1,
            'reorder_point'      => 0,
        ]);

        $this->order($product, 'USD', 4500, ['outlet_id' => $outlet->id]);

        $this->artisan('orders:reprice-currency --apply')->assertSuccessful();

        $inv->refresh();
        $this->assertSame(10, (int) $inv->quantity_on_hand);
        $this->assertSame(1, (int) $inv->quantity_reserved);
    }
}
