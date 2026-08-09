<?php

namespace Tests\Feature;

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
 * Actionable alerts — POST /purchase-orders/from-suggestions builds draft
 * PO(s) from the same three real numbers as the purchase-suggestion report
 * (available, reorder buffer, open demand), priced at the last price
 * actually paid, grouped one draft per most-recent supplier. And the
 * attention feed advertises the action: material_runway carries a
 * create_po action with the material ids to feed this endpoint.
 */
class PurchaseOrderFromSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('procurement_officer', 'sanctum'));
        // The whole procurement route group sits behind procurement.view;
        // the from-suggestions endpoint additionally demands procurement.create.
        $user->givePermissionTo(Permission::findOrCreate('procurement.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('procurement.create', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function material(string $code, string $name, float $unitCost, float $reorder, ?Outlet $outlet = null, float $available = 0): int
    {
        $id = DB::table('materials')->insertGetId([
            'code' => $code, 'name' => $name, 'unit_of_measure' => 'm',
            'unit_cost' => $unitCost, 'reorder_point' => $reorder, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($outlet && $available > 0) {
            DB::table('material_inventory')->insert([
                'material_id' => $id, 'outlet_id' => $outlet->id,
                'quantity_on_hand' => $available, 'quantity_reserved' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function supplier(string $code, string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => $code, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pastPurchase(int $supplierId, int $materialId, float $price, int $daysAgo, ?Outlet $outlet = null): void
    {
        static $seq = 0;
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-HIST-' . (++$seq), 'supplier_id' => $supplierId,
            'outlet_id' => $outlet?->id,
            'order_date' => now()->subDays($daysAgo)->format('Y-m-d'), 'status' => 'received',
            'subtotal' => $price * 10, 'total_amount' => $price * 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId, 'item_type' => 'material', 'material_id' => $materialId,
            'description' => 'History line', 'quantity' => 10, 'unit_price' => $price, 'total_price' => $price * 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_creates_one_draft_po_per_supplier_with_suggested_quantities_and_last_prices(): void
    {
        $outlet = Outlet::factory()->create();
        $this->buyer();

        // Navy: reorder 50, available 20 → suggested 30; last bought at 780 from Nairobi.
        $navy = $this->material('FAB-NAVY', 'Navy Wool', 800, 50, $outlet, 20);
        // Gold: reorder 10, nothing available → suggested 10; last bought at 200 from Mombasa.
        $gold = $this->material('THR-GOLD', 'Gold Thread', 250, 10);

        $nairobi = $this->supplier('SUP-NBO', 'Nairobi Textiles');
        $mombasa = $this->supplier('SUP-MSA', 'Mombasa Fabrics');
        $this->pastPurchase($nairobi, $navy, 780, 20, $outlet);
        $this->pastPurchase($mombasa, $gold, 200, 15, $outlet);

        $res = $this->postJson('/api/v1/admin/purchase-orders/from-suggestions', [
            'material_ids' => [$navy, $gold],
        ])->assertStatus(201);

        $pos = collect($res->json('purchase_orders'));
        $this->assertCount(2, $pos);

        $navyPo = DB::table('purchase_orders')->where('id', $pos->firstWhere('supplier_id', $nairobi)['id'])->first();
        $this->assertSame('draft', $navyPo->status);
        $this->assertSame('KES', $navyPo->currency_code);
        $this->assertSame(now()->addDays(7)->toDateString(), \Carbon\Carbon::parse($navyPo->expected_delivery_date)->toDateString());
        $this->assertSame('Draft from stock-runway suggestion', $navyPo->notes);

        $navyItem = DB::table('purchase_order_items')->where('purchase_order_id', $navyPo->id)->first();
        $this->assertSame(30.0, (float) $navyItem->quantity);       // 50 buffer − 20 available
        $this->assertSame(780.0, (float) $navyItem->unit_price);    // last price, not unit_cost
        $this->assertSame(23400.0, (float) $navyPo->total_amount);  // 30 × 780

        $goldPo = DB::table('purchase_orders')->where('id', $pos->firstWhere('supplier_id', $mombasa)['id'])->first();
        $goldItem = DB::table('purchase_order_items')->where('purchase_order_id', $goldPo->id)->first();
        $this->assertSame(10.0, (float) $goldItem->quantity);
        $this->assertSame(200.0, (float) $goldItem->unit_price);
    }

    public function test_material_without_history_rides_with_the_most_recent_supplier(): void
    {
        $outlet = Outlet::factory()->create();
        $this->buyer();

        $navy  = $this->material('FAB-NAVY2', 'Navy Wool', 800, 50, $outlet, 20);
        $fresh = $this->material('FAB-NEW', 'Brand-new Linen', 500, 5);   // never purchased

        $nairobi = $this->supplier('SUP-NBO2', 'Nairobi Textiles');
        $this->pastPurchase($nairobi, $navy, 780, 20, $outlet);

        $res = $this->postJson('/api/v1/admin/purchase-orders/from-suggestions', [
            'material_ids' => [$navy, $fresh],
        ])->assertStatus(201);

        // ONE draft: the history-less material joins the most recent supplier.
        $pos = $res->json('purchase_orders');
        $this->assertCount(1, $pos);
        $this->assertSame($nairobi, $pos[0]['supplier_id']);

        $freshItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $pos[0]['id'])->where('material_id', $fresh)->first();
        $this->assertSame(5.0, (float) $freshItem->quantity);       // reorder buffer, nothing available
        $this->assertSame(500.0, (float) $freshItem->unit_price);   // no last price → unit_cost
    }

    public function test_rejects_when_no_material_has_purchase_history(): void
    {
        $this->buyer();
        $lonely = $this->material('FAB-LONELY', 'Uncharted Fabric', 300, 10);

        $this->postJson('/api/v1/admin/purchase-orders/from-suggestions', [
            'material_ids' => [$lonely],
        ])->assertStatus(422);
    }

    public function test_requires_procurement_create_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $material = $this->material('FAB-DENIED', 'Denied Fabric', 300, 10);

        $this->postJson('/api/v1/admin/purchase-orders/from-suggestions', [
            'material_ids' => [$material],
        ])->assertStatus(403);
    }

    public function test_material_runway_attention_item_advertises_the_create_po_action(): void
    {
        $outlet = Outlet::factory()->create();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('procurement_manager', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $materialId = $this->material('THR-RW', 'Runway Thread', 300, 0, $outlet, 20);

        // 60 units burned in 30 days → 2/day; 20 left → 10-day runway.
        $product = Product::factory()->create();
        $poId = DB::table('production_orders')->insertGetId([
            'order_number' => 'PRD-AA-RW', 'product_id' => $product->id,
            'quantity' => 30, 'status' => 'in_progress',
            'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);
        DB::table('material_allocations')->insert([
            'production_order_id' => $poId, 'material_id' => $materialId,
            'quantity_required' => 80, 'quantity_allocated' => 80, 'quantity_used' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'material_runway');

        $this->assertNotNull($item);
        // Original flat keys untouched (the digest email depends on them)…
        foreach (['key', 'severity', 'title', 'detail', 'count', 'link'] as $k) {
            $this->assertArrayHasKey($k, $item);
        }
        // …plus the additive action + entity payload.
        $createPo = collect($item['actions'])->firstWhere('type', 'create_po');
        $this->assertNotNull($createPo);
        $this->assertSame([$materialId], $createPo['material_ids']);
        $this->assertSame($materialId, $item['entities'][0]['material_id']);
        $this->assertSame(10.0, (float) $item['entities'][0]['runway_days']);
    }
}
