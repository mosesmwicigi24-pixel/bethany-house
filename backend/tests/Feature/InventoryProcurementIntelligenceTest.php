<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4 — inventory & procurement intelligence.
 *
 * Valuation prices stock from product_prices (KES, cost + retail); ABC
 * classifies by cumulative revenue share (80/95); purchase suggestions are
 * three real numbers per material — available, reorder buffer, open
 * production demand — never a guess.
 */
class InventoryProcurementIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('inventory_manager', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function stockedProduct(Outlet $outlet, int $qty, float $cost, float $retail): Product
    {
        $product = Product::factory()->create();
        DB::table('inventory_items')->insert([
            'product_id' => $product->id, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => $qty, 'quantity_reserved' => 0, 'reorder_point' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'currency_code' => 'KES',
            'regular_price' => $retail, 'cost_price' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    private function sell(Outlet $outlet, Product $product, int $qty, float $lineTotal, $when = null): void
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $outlet->id,
            'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $lineTotal, 'currency_code' => 'KES',
            'created_at' => $when ?? now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}",
            'product_name' => "Product {$product->id}", 'quantity' => $qty,
            'unit_price' => $lineTotal / max(1, $qty), 'total_price' => $lineTotal,
            'created_at' => $when ?? now(), 'updated_at' => $when ?? now(),
        ]);
    }

    public function test_inventory_valuation_prices_stock_at_cost_and_retail(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $this->stockedProduct($outlet, 10, 500, 900);   // 5,000 cost / 9,000 retail
        $this->stockedProduct($outlet, 4, 250, 400);    // 1,000 cost / 1,600 retail

        $res = $this->getJson('/api/v1/admin/reports/inventory-intelligence')->assertOk();

        $this->assertSame(2, $res->json('health.skus'));
        $this->assertSame(14, $res->json('health.units'));
        $this->assertSame(6000.0,  (float) $res->json('health.cost_value'));
        $this->assertSame(10600.0, (float) $res->json('health.retail_value'));
    }

    public function test_abc_classification_follows_cumulative_revenue_share(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $star   = $this->stockedProduct($outlet, 50, 100, 200);
        $middle = $this->stockedProduct($outlet, 50, 100, 200);
        $tail   = $this->stockedProduct($outlet, 50, 100, 200);

        $this->sell($outlet, $star, 40, 80000);   // 80% of revenue → A
        $this->sell($outlet, $middle, 10, 15000); // next 15% → B
        $this->sell($outlet, $tail, 5, 5000);     // last 5% → C

        $res = $this->getJson('/api/v1/admin/reports/inventory-intelligence?period=last_30')->assertOk();
        $items = collect($res->json('abc.items'))->keyBy('product_id');

        $this->assertSame('A', $items[$star->id]['class']);
        $this->assertSame('B', $items[$middle->id]['class']);
        $this->assertSame('C', $items[$tail->id]['class']);
        $this->assertSame(1, $res->json('abc.classes.A.count'));
        $this->assertSame(80000.0, (float) $res->json('abc.classes.A.revenue'));

        // Star sells 40/30 days ≈ 1.33/day; 50 on hand ≈ 37.5 days of cover.
        $this->assertSame(37.5, (float) $items[$star->id]['cover_days']);
    }

    public function test_stockout_risk_flags_a_class_items_with_thin_cover_on_the_attention_feed(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        // 60 sold in 30 days = 2/day; only 6 left = 3 days of cover.
        $hot = $this->stockedProduct($outlet, 6, 100, 200);
        $this->sell($outlet, $hot, 60, 120000, now()->subDays(10));

        $res = $this->getJson('/api/v1/admin/reports/inventory-intelligence')->assertOk();
        $risks = $res->json('stockout_risks');
        $this->assertCount(1, $risks);
        $this->assertSame(3.0, (float) $risks[0]['cover_days']);

        $exec = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($exec->json('attention'))->firstWhere('key', 'stockout_risk');
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
    }

    public function test_dead_stock_lists_unsold_stock_and_spares_recent_sellers(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $dusty  = $this->stockedProduct($outlet, 8, 100, 200);
        $moving = $this->stockedProduct($outlet, 8, 100, 200);
        $this->sell($outlet, $dusty, 1, 200, now()->subDays(120));
        $this->sell($outlet, $moving, 1, 200, now()->subDays(5));

        $res = $this->getJson('/api/v1/admin/reports/inventory-intelligence')->assertOk();
        $ids = collect($res->json('dead_stock'))->pluck('product_id');

        $this->assertTrue($ids->contains($dusty->id));
        $this->assertFalse($ids->contains($moving->id));
    }

    public function test_purchase_suggestions_stack_open_demand_on_the_reorder_buffer(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'FAB-NAVY', 'name' => 'Navy Wool', 'unit_of_measure' => 'm',
            'unit_cost' => 800, 'reorder_point' => 50, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('material_inventory')->insert([
            'material_id' => $materialId, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::factory()->create();
        $poId = DB::table('production_orders')->insertGetId([
            'order_number' => 'PRD-P4-SUG', 'product_id' => $product->id,
            'quantity' => 10, 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('material_allocations')->insert([
            'production_order_id' => $poId, 'material_id' => $materialId,
            'quantity_required' => 40, 'quantity_allocated' => 10, 'quantity_used' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-01', 'name' => 'Nairobi Textiles', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-P4-1', 'supplier_id' => $supplierId, 'outlet_id' => $outlet->id,
            'order_date' => now()->subDays(20)->format('Y-m-d'), 'status' => 'received',
            'subtotal' => 64000, 'total_amount' => 64000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $purchaseId, 'item_type' => 'material', 'material_id' => $materialId,
            'description' => 'Navy Wool 80m', 'quantity' => 80, 'unit_price' => 780, 'total_price' => 62400,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/procurement-intelligence')->assertOk();
        $row = collect($res->json('suggestions'))->firstWhere('code', 'FAB-NAVY');

        $this->assertNotNull($row);
        // Available 20 (on hand, nothing reserved); open demand 40−10 = 30;
        // target = 50 buffer + 30 demand = 80 → suggest 60.
        $this->assertSame(30.0, (float) $row['open_demand']);
        $this->assertSame(60.0, (float) $row['suggested']);
        $this->assertSame(48000.0, (float) $row['est_cost']);
        $this->assertSame('Nairobi Textiles', $row['last_supplier']);
        $this->assertSame(780.0, (float) $row['last_price']);
    }

    public function test_supplier_scorecard_measures_actual_delivery_against_promise(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-02', 'name' => 'Mombasa Fabrics', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-P4-2', 'supplier_id' => $supplierId, 'outlet_id' => $outlet->id,
            'order_date' => now()->subDays(10)->format('Y-m-d'),
            'expected_delivery_date' => now()->subDays(5)->format('Y-m-d'),
            'status' => 'received', 'subtotal' => 30000, 'total_amount' => 30000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('goods_received_notes')->insert([
            'grn_number' => 'GRN-P4-1', 'purchase_order_id' => $poId, 'outlet_id' => $outlet->id,
            'received_date' => now()->subDays(3)->format('Y-m-d'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/procurement-intelligence?period=this_quarter')->assertOk();
        $row = collect($res->json('suppliers'))->firstWhere('supplier', 'Mombasa Fabrics');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['orders']);
        // Ordered 10 days ago, received 3 days ago → 7 days; promised in 5 → late.
        $this->assertSame(7.0, (float) $row['avg_delivery_days']);
        $this->assertSame(1, $row['late']);
    }

    public function test_shrinkage_counts_only_negative_approved_write_offs(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $lost = $this->stockedProduct($outlet, 50, 100, 200);
        $itemId = DB::table('inventory_items')->where('product_id', $lost->id)->value('id');

        DB::table('inventory_transactions')->insert([
            // 5 damaged at cost 100 and 2 stolen at cost 200 = 7 units / 900 lost.
            ['inventory_item_id' => $itemId, 'transaction_type' => 'adjustment', 'reason_code' => 'damaged',
             'quantity_change' => -5, 'quantity_before' => 50, 'quantity_after' => 45, 'unit_cost' => 100,
             'status' => 'approved', 'created_at' => now()],
            ['inventory_item_id' => $itemId, 'transaction_type' => 'stolen', 'reason_code' => null,
             'quantity_change' => -2, 'quantity_before' => 45, 'quantity_after' => 43, 'unit_cost' => 200,
             'status' => 'approved', 'created_at' => now()],
            // A sale and a found-stock increase are movements, not losses.
            ['inventory_item_id' => $itemId, 'transaction_type' => 'sale', 'reason_code' => null,
             'quantity_change' => -10, 'quantity_before' => 43, 'quantity_after' => 33, 'unit_cost' => 100,
             'status' => 'approved', 'created_at' => now()],
            ['inventory_item_id' => $itemId, 'transaction_type' => 'adjustment', 'reason_code' => 'found',
             'quantity_change' => 3, 'quantity_before' => 33, 'quantity_after' => 36, 'unit_cost' => 100,
             'status' => 'approved', 'created_at' => now()],
        ]);

        $res = $this->getJson('/api/v1/admin/reports/inventory-intelligence?period=last_30')->assertOk();

        $this->assertSame(7, $res->json('shrinkage.units'));
        $this->assertSame(900.0, (float) $res->json('shrinkage.value'));
        $reasons = collect($res->json('shrinkage.by_reason'))->keyBy('reason');
        $this->assertSame(500.0, (float) $reasons['damaged']['value']);
        $this->assertSame(400.0, (float) $reasons['stolen']['value']);
    }

    public function test_reports_view_gates_both_endpoints(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/inventory-intelligence')->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/procurement-intelligence')->assertStatus(403);
    }
}
