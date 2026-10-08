<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The inventory valuation must value the stock that exists.
 *
 * It inner-joined product_variants, so stock on a product with no variants was
 * dropped without trace. On 2026-09-29 that was 91 of 164 SKUs and 47,264 of
 * 50,967 available units: KES 26.6m reported against the engine's 86.7m for
 * the same shelves. A valuation that quietly omits most of the warehouse is
 * worse than no valuation, because it is believed.
 */
class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        $this->outlet = Outlet::factory()->create();
    }

    private function valuation(): array
    {
        return $this->getJson('/api/v1/admin/reports/inventory/valuation')->assertOk()->json();
    }

    /** A product with no variants — the case that used to vanish. */
    private function simpleProduct(float $price, int $onHand, int $reserved = 0): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $price,
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function variantProduct(float $price, int $onHand, int $reserved = 0): ProductVariant
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'currency_code' => 'KES', 'regular_price' => $price,
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved, 'reorder_point' => 0,
        ]);

        return $variant;
    }

    public function test_stock_on_a_product_without_variants_is_valued(): void
    {
        $this->simpleProduct(price: 2_000, onHand: 10);

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(1, (int) $grand['total_sku_count'], 'the simple product must appear');
        $this->assertSame(10, (int) $grand['total_units']);
        $this->assertSame(20_000.0, (float) $grand['total_retail_value']);
    }

    public function test_simple_and_variant_stock_are_valued_together(): void
    {
        $this->simpleProduct(price: 2_000, onHand: 10);   // 20,000
        $this->variantProduct(price: 500, onHand: 4);     //  2,000

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(2, (int) $grand['total_sku_count']);
        $this->assertSame(14, (int) $grand['total_units']);
        $this->assertSame(22_000.0, (float) $grand['total_retail_value']);
    }

    public function test_the_headline_values_available_stock_and_reports_on_hand_beside_it(): void
    {
        // 10 on the shelf, 4 promised to orders already placed.
        $this->simpleProduct(price: 1_000, onHand: 10, reserved: 4);

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(6, (int) $grand['total_units'], 'available = on hand less reserved');
        $this->assertSame(6_000.0, (float) $grand['total_retail_value']);
        $this->assertSame(10, (int) $grand['total_units_on_hand'], 'on-hand reported beside it');
        $this->assertSame(10_000.0, (float) $grand['total_retail_value_on_hand']);
        $this->assertSame('available (on hand less reserved)', $grand['basis'], 'the basis is stated');
    }

    public function test_a_variant_price_wins_over_the_products_own_price(): void
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        // Both rows exist; the variant is the specific thing on the shelf.
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'KES', 'regular_price' => 100]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
                              'currency_code' => 'KES', 'regular_price' => 900]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
                               'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 2,
                               'quantity_reserved' => 0, 'reorder_point' => 0]);

        $this->assertSame(1_800.0, (float) $this->valuation()['grand_totals']['total_retail_value']);
    }

    public function test_stock_with_no_kes_price_counts_as_units_but_adds_no_value(): void
    {
        // 12 of the 373 products have no KES price. They are still stock; they
        // are simply worth nothing the report can state.
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null,
                               'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 7,
                               'quantity_reserved' => 0, 'reorder_point' => 0]);

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(7, (int) $grand['total_units']);
        $this->assertSame(0.0, (float) $grand['total_retail_value']);
    }

    public function test_a_price_in_another_currency_is_not_read_as_shillings(): void
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'USD', 'regular_price' => 200]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null,
                               'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 3,
                               'quantity_reserved' => 0, 'reorder_point' => 0]);

        // USD 200 must not be counted as KES 200 — this valuation is KES only.
        $this->assertSame(0.0, (float) $this->valuation()['grand_totals']['total_retail_value']);
    }

    public function test_nothing_available_means_nothing_valued(): void
    {
        $this->simpleProduct(price: 5_000, onHand: 3, reserved: 3);

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(0, (int) $grand['total_sku_count'], 'fully reserved stock is not available');
        $this->assertSame(0.0, (float) $grand['total_retail_value']);
    }

    public function test_a_duplicate_price_row_cannot_multiply_the_stock(): void
    {
        // unique_product_price is (product_id, product_variant_id, currency_code)
        // and Postgres counts NULLs as distinct, so two product-level KES rows
        // are permitted. Joining them raw would double every unit of that
        // product. None exists in production today; this pins that a second row
        // cannot inflate the valuation if one ever appears.
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'KES', 'regular_price' => 1_000]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'KES', 'regular_price' => 1_000]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null,
                               'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 5,
                               'quantity_reserved' => 0, 'reorder_point' => 0]);

        $grand = $this->valuation()['grand_totals'];

        $this->assertSame(5, (int) $grand['total_units'], 'five units, not ten');
        $this->assertSame(5_000.0, (float) $grand['total_retail_value']);
    }

    public function test_the_report_needs_its_permission(): void
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->getJson('/api/v1/admin/reports/inventory/valuation')->assertStatus(403);
    }
}
