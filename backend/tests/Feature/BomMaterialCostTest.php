<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A BOM line costs the material's own cost (owner, 2026-11-10): a material
 * with none says so, the total says how many lines it leaves out, a cost set
 * from the BOM lands on the material (one cost, never a second price in the
 * BOM), and each cost says where it came from.
 */
class BomMaterialCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function owner(): User
    {
        $u = User::factory()->create(['status' => 'active', 'first_name' => 'Moses', 'last_name' => 'Mwicigi']);
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());

        return $u->fresh();
    }

    /** A product whose BOM is 5 m of Cream White and 2 m of Golden self print, neither costed. */
    private function bom(): array
    {
        $product = Product::factory()->create();
        $white = DB::table('materials')->insertGetId(['code' => 'FAB-CW', 'name' => 'Cream White', 'unit_of_measure' => 'meters', 'unit_cost' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $gold  = DB::table('materials')->insertGetId(['code' => 'FAB-GSP', 'name' => 'Golden self print', 'unit_of_measure' => 'meters', 'unit_cost' => null, 'created_at' => now(), 'updated_at' => now()]);
        $bomId = DB::table('bills_of_materials')->insertGetId(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bom_items')->insert([
            ['bom_id' => $bomId, 'material_id' => $white, 'quantity' => 5, 'unit_of_measure' => 'meters', 'created_at' => now(), 'updated_at' => now()],
            ['bom_id' => $bomId, 'material_id' => $gold, 'quantity' => 2, 'unit_of_measure' => 'meters', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$product, $bomId, $white, $gold];
    }

    private function show(Product $product, int $bomId): array
    {
        return $this->getJson("/api/v1/admin/products/{$product->id}/bom/{$bomId}")->assertOk()->json('bom');
    }

    public function test_an_uncosted_material_says_so_and_the_total_counts_it_out(): void
    {
        $this->owner();
        [$product, $bomId] = $this->bom();

        $bom = $this->show($product, $bomId);
        $this->assertSame(0.0, (float) $bom['total_cost']);
        $this->assertSame(2, $bom['uncosted_count']);
        $this->assertNull($bom['items'][0]['material']['cost_source']);
    }

    public function test_a_cost_set_from_the_bom_lands_on_the_material_and_says_who_set_it(): void
    {
        $this->owner();
        [$product, $bomId, $white] = $this->bom();

        // What the BOM's "No cost · set" sends: the material's own cost (a
        // recorded supplier-cost change; a super admin's applies at once).
        $this->putJson("/api/v1/admin/inventory/materials/{$white}", ['unit_cost' => 450])->assertOk();
        $this->assertSame(450.0, (float) DB::table('materials')->where('id', $white)->value('unit_cost'));

        $bom = $this->show($product, $bomId);
        // Independent arithmetic: 5 m × KES 450 = 2,250; the gold line is still uncosted.
        $this->assertSame(2250.0, (float) $bom['total_cost']);
        $this->assertSame(1, $bom['uncosted_count']);
        $src = $bom['items'][0]['material']['cost_source'];
        $this->assertSame('manual', $src['kind']);
        $this->assertSame('Moses Mwicigi', $src['by']);
        $this->assertStringStartsWith('CHG-', $src['ref']);
        $this->assertDatabaseHas('change_proposals', ['event' => 'supplier_cost_change', 'subject_id' => $white, 'status' => 'applied']);
    }

    public function test_a_cost_from_a_received_purchase_order_names_the_order(): void
    {
        $this->owner();
        [$product, $bomId, , $gold] = $this->bom();
        DB::table('materials')->where('id', $gold)->update(['unit_cost' => 300]);
        $outlet = Outlet::factory()->create();
        $supplier = DB::table('suppliers')->insertGetId(['code' => 'SUP-1', 'name' => 'Textiles Ltd', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $po = DB::table('purchase_orders')->insertGetId(['po_number' => 'PO-0123', 'supplier_id' => $supplier, 'outlet_id' => $outlet->id, 'order_date' => now()->toDateString(),
            'status' => 'received', 'subtotal' => 600, 'total_amount' => 600, 'created_at' => now(), 'updated_at' => now()]);
        $inv = DB::table('material_inventory')->insertGetId(['material_id' => $gold, 'outlet_id' => $outlet->id, 'quantity_on_hand' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('material_transactions')->insert(['material_inventory_id' => $inv, 'transaction_type' => 'purchase', 'reference_type' => 'purchase_order',
            'reference_id' => $po, 'quantity_change' => 2, 'quantity_before' => 0, 'quantity_after' => 2, 'created_at' => now()]);

        $bom = $this->show($product, $bomId);
        $src = $bom['items'][1]['material']['cost_source'];
        $this->assertSame('purchase', $src['kind']);
        $this->assertSame('PO-0123', $src['ref']);
        // 2 m × KES 300 = 600; the white line is uncosted.
        $this->assertSame(600.0, (float) $bom['total_cost']);
        $this->assertSame(1, $bom['uncosted_count']);
    }

    public function test_someone_who_may_not_see_cost_gets_no_cost_source_either(): void
    {
        [$product, $bomId] = $this->bom();
        $u = User::factory()->create(['status' => 'active']);
        foreach (['products.view', 'production.view_bom'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());

        $bom = $this->show($product, $bomId);
        $this->assertArrayNotHasKey('uncosted_count', $bom);
        $this->assertArrayNotHasKey('cost_source', $bom['items'][0]['material']);
        $this->assertArrayNotHasKey('total_cost', $bom);
    }
}
