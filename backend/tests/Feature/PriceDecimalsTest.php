<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Support\CustomerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Prices keep two decimals (owner, 2026-11-16: "Make the unit also receive
 * decimal points", then "No rounding off — 400 plastic cups × 0.1 USD should
 * be 40 USD"). A unit price, typed or from the price list, converted or not,
 * is never rounded to a whole unit, and the totals carry the cents. Fees stay
 * whole as before (CustomerMoney, 2026-10-30): a discount, a shipping fee.
 *
 * Figures are worked by hand below, not with the code's own formula.
 */
class PriceDecimalsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach ([['KES', 1, true, 1], ['USD', 0.01, false, 128]] as [$code, $rate, $base, $books]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], [
                'name' => $code, 'symbol' => $code, 'exchange_rate' => $rate, 'reporting_rate_to_kes' => $books,
                'is_base' => $base, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Services\CurrencyPricing::forget();
        $this->outlet = Outlet::factory()->create();

        Role::findOrCreate('super_admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function product(float $kes): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Wafer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $kes, 'sale_price' => null, 'cost_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    /** A USD order with one line of 2 × 44.00, unpaid. */
    private function order(Product $p): array
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'pending', 'payment_status' => 'pending',
            'outlet_id' => $this->outlet->id, 'currency_code' => 'USD',
            'subtotal' => 88, 'discount_amount' => 0, 'tax_amount' => 0,
            'prices_include_tax' => true, 'shipping_amount' => 0, 'total_amount' => 88,
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'product_name' => 'Wafer', 'sku' => $p->sku,
            'quantity' => 2, 'unit_price' => 44, 'discount_amount' => 0, 'tax_amount' => 0,
            'total_price' => 88, 'cost_price' => 100, 'cost_source' => 'product_price',
            'inventory_item_id' => InventoryItem::where('product_id', $p->id)->value('id'),
        ]);

        return [$order, $line];
    }

    public function test_the_rule_itself(): void
    {
        $this->assertSame(44.5, CustomerMoney::price(44.5));
        $this->assertSame(44.57, CustomerMoney::price(44.569));
        $this->assertSame(0.0, CustomerMoney::price(0));
        $this->assertSame(45.0, CustomerMoney::whole(44.5));                 // everything else unchanged
    }

    public function test_adjusting_a_lines_price_keeps_the_cents(): void
    {
        [$order, $line] = $this->order($this->product(4400));

        $this->patchJson("/api/v1/admin/orders/{$order->id}/items/{$line->id}/price", ['unit_price' => 44.5])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(44.5, (float) $line->fresh()->unit_price, 0.0001);
        $this->assertEqualsWithDelta(89.0, (float) $line->fresh()->total_price, 0.0001);   // 2 × 44.50
        $this->assertEqualsWithDelta(89.0, (float) $order->fresh()->total_amount, 0.0001);
    }

    public function test_editing_lines_keeps_the_cents_typed_or_from_the_catalogue(): void
    {
        $wafer  = $this->product(4400);
        $bread  = $this->product(1049);                 // KES 1,049 → USD 10.49
        [$order, $line] = $this->order($wafer);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", ['items' => [
            ['id' => $line->id, 'quantity' => 2, 'unit_price' => 44.75],
            ['product_id' => $bread->id, 'quantity' => 1],                       // no price typed
            ['product_id' => $bread->id, 'quantity' => 3, 'unit_price' => 12.25],
        ]])->assertOk();

        $lines = $order->fresh()->items()->orderBy('id')->get();
        $this->assertEqualsWithDelta(44.75, (float) $lines[0]->unit_price, 0.0001);
        $this->assertEqualsWithDelta(10.49, (float) $lines[1]->unit_price, 0.0001);
        $this->assertEqualsWithDelta(12.25, (float) $lines[2]->unit_price, 0.0001);
        // 2 × 44.75 + 10.49 + 3 × 12.25 = 89.50 + 10.49 + 36.75 = 136.74
        $this->assertEqualsWithDelta(136.74, (float) $order->fresh()->total_amount, 0.0001);
    }

    public function test_400_cups_at_ten_cents_come_to_forty_dollars(): void
    {
        // The owner's own example. KES 10 a cup is USD 0.10 at the pricing
        // rate (0.01); it used to be rounded to USD 0 on the line.
        $cups = $this->product(10);
        [$order, $line] = $this->order($cups);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", ['items' => [
            ['id' => $line->id, 'quantity' => 2],
            ['product_id' => $cups->id, 'quantity' => 400],                      // from the price list
        ]])->assertOk();

        $new = $order->fresh()->items()->orderByDesc('id')->first();
        $this->assertEqualsWithDelta(0.10, (float) $new->unit_price, 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $new->total_price, 0.0001);   // 400 × 0.10

        // A USD price saved on a product keeps its cents too.
        DB::table('product_prices')->where('product_id', $cups->id)->delete();
        $saved = \App\Models\ProductPrice::create([
            'product_id' => $cups->id, 'currency_code' => 'USD', 'regular_price' => 0.1, 'cost_price' => 0,
        ]);
        $this->assertEqualsWithDelta(0.10, (float) $saved->fresh()->regular_price, 0.0001);
        $this->assertEqualsWithDelta(149.99, CustomerMoney::listPrice(149.99, 'USD'), 0.0001);
    }

    public function test_a_quoted_price_keeps_its_cents_and_a_discount_stays_whole(): void
    {
        $this->postJson('/api/v1/admin/quotations', [
            'currency_code' => 'USD',
            'items' => [
                ['product_name' => 'Wafer', 'quantity' => 4, 'unit_price' => 45.25, 'discount_amount' => 2.4],
            ],
        ])->assertCreated();

        $item = Quotation::first()->items()->first();
        $this->assertEqualsWithDelta(45.25, (float) $item->unit_price, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $item->discount_amount, 0.0001);   // whole, as before
    }
}
