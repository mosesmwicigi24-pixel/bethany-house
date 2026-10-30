<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\ShippingMethod;
use App\Services\CurrencyPricing;
use App\Services\PromotionService;
use App\Services\TaxCalculationService;
use App\Support\CustomerMoney;
use App\Support\ShippingFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every figure a customer is billed is a whole unit (owner, 2026-10-30):
 * prices, discounts, shipping and VAT added on top. VAT already inside a
 * whole price keeps its cents — the customer pays the whole price either way.
 *
 * Every expected figure below is worked by hand in its comment, not by the
 * code under test.
 */
class CustomerMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currency('KES', 1.0, isBase: true);
        $this->currency('USD', 0.0077);
    }

    private function currency(string $code, float $rate, bool $isBase = false): void
    {
        DB::table('currencies')->updateOrInsert(['code' => $code], [
            'name' => $code, 'symbol' => $code, 'exchange_rate' => $rate,
            'is_base' => $isBase, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CurrencyPricing::forget();
    }

    private function vat(float $percent): void
    {
        DB::table('tax_rates')->insert([
            'name' => 'VAT', 'code' => 'VAT', 'rate' => $percent,
            'is_active' => true, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        TaxCalculationService::invalidateGlobalCache();
    }

    public function test_halves_round_up_and_everything_else_to_the_nearest_unit(): void
    {
        $this->assertSame(3.0, CustomerMoney::whole(2.5));
        $this->assertSame(2.0, CustomerMoney::whole(2.49));
        $this->assertSame(2308.0, CustomerMoney::whole(2307.69));
        $this->assertSame(0.0, CustomerMoney::whole(0.0));
        $this->assertNull(CustomerMoney::wholeOrNull(null));
    }

    public function test_a_price_typed_with_cents_is_billed_whole_and_the_price_list_keeps_it(): void
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'USD', 'regular_price' => 149.99]);

        $priced = CurrencyPricing::catalogue($product->id, null, 'USD');

        $this->assertSame(150.0, $priced['regular_price']);            // 149.99 → 150
        $this->assertSame(150.0, $priced['effective_price']);
        $this->assertEqualsWithDelta(149.99, (float) ProductPrice::where('product_id', $product->id)->value('regular_price'), 0.001);
    }

    public function test_a_shipping_fee_is_whole_in_every_currency(): void
    {
        DB::table('countries')->insertOrIgnore(['code' => 'KE', 'name' => 'Kenya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $zone = DB::table('shipping_zones')->insertGetId(['name' => 'Kenya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $flat = ShippingMethod::create(['shipping_zone_id' => $zone, 'name' => 'Courier', 'cost_type' => 'flat_rate', 'flat_rate' => 500, 'is_active' => true, 'sort_order' => 0]);
        $pct  = ShippingMethod::create(['shipping_zone_id' => $zone, 'name' => 'Insured', 'cost_type' => 'percentage', 'flat_rate' => 2.5, 'is_active' => true, 'sort_order' => 1]);

        $this->assertSame(500.0, ShippingFee::inCurrency($flat, 0.0, 'KES'));
        $this->assertSame(4.0, ShippingFee::inCurrency($flat, 0.0, 'USD'));     // 500 × 0.0077 = 3.85 → 4
        $this->assertSame(25.0, ShippingFee::inCurrency($pct, 999.0, 'KES'));   // 2.5% of 999 = 24.975 → 25
    }

    public function test_vat_added_on_top_is_whole_and_vat_inside_a_price_keeps_its_cents(): void
    {
        $this->vat(16.0);
        $product = Product::factory()->create();

        // On top: 999 × 16% = 159.84 → 160; the customer pays 1,159.
        $onTop = TaxCalculationService::calculateLine(999.0, 1, $product->id, false);
        $this->assertSame(160.0, $onTop['tax_amount']);
        $this->assertEqualsWithDelta(1159.0, $onTop['subtotal_gross'], 0.0001);

        // Inside: 1,000 − 1,000 ÷ 1.16 = 137.93; the customer pays 1,000.
        $inside = TaxCalculationService::calculateLine(1000.0, 1, $product->id, true);
        $this->assertEqualsWithDelta(137.93, $inside['tax_amount'], 0.005);
        $this->assertEqualsWithDelta(1000.0, $inside['subtotal_gross'], 0.0001);
    }

    public function test_a_promotion_price_is_whole_and_never_above_the_price(): void
    {
        $service = app(PromotionService::class);
        $pct  = new Promotion(['discount_type' => 'percentage', 'discount_value' => 15]);
        $flat = new Promotion(['discount_type' => 'fixed', 'discount_value' => 0.4]);

        $this->assertSame(849.0, $service->discountedUnit(999.0, $pct));   // 999 − 149.85 = 849.15 → 849
        $this->assertSame(1000.0, $service->discountedUnit(1000.0, $flat)); // 999.60 → 1,000, no higher than the price
    }

    /** An order written before 30 Oct: USD 34.65 × 2 less a 0.35 discount, 3.85 shipping. */
    private function centsOrder(string $paymentStatus = 'pending'): Order
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'status' => 'pending', 'payment_status' => $paymentStatus, 'currency_code' => 'USD',
            'prices_include_tax' => true, 'subtotal' => 69.30, 'discount_amount' => 0.35,
            'tax_amount' => 0, 'shipping_amount' => 3.85, 'total_amount' => 72.80,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Tray',
            'sku' => $product->sku, 'quantity' => 2, 'unit_price' => 34.65, 'total_price' => 69.30,
        ]);

        return $order;
    }

    public function test_the_repair_lists_by_default_and_rebills_only_unpaid_orders_on_apply(): void
    {
        $open  = $this->centsOrder();
        $paid  = $this->centsOrder('paid');
        $actor = User::factory()->create(['status' => 'active']);

        // 34.65 → 35 × 2 = 70; discount 0.35 → 0; shipping 3.85 → 4. Total 74.
        $this->artisan('orders:whole-amounts')
            ->expectsOutputToContain("Order #{$open->id} {$open->order_number} USD 72.80 → would be USD 74.00")
            ->expectsOutputToContain("Order #{$paid->id} {$paid->order_number} USD 72.80 → paid or part-paid (paid), left as is")
            ->assertSuccessful();
        $this->assertEqualsWithDelta(72.80, (float) $open->fresh()->total_amount, 0.001, 'a dry run writes nothing');

        $this->artisan('orders:whole-amounts', ['--apply' => true, '--actor' => (string) $actor->id])->assertSuccessful();

        $open = $open->fresh(['items']);
        $this->assertEqualsWithDelta(74.0, (float) $open->total_amount, 0.001);
        $this->assertEqualsWithDelta(70.0, (float) $open->subtotal, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $open->discount_amount, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $open->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(35.0, (float) $open->items->first()->unit_price, 0.001);
        $this->assertEqualsWithDelta(72.80, (float) $paid->fresh()->total_amount, 0.001, 'money that moved is never restated');

        // Safe to run again: nothing left to round but the paid one.
        $this->artisan('orders:whole-amounts')->expectsOutputToContain('Would round 0; left paid 1')->assertSuccessful();
    }
}
