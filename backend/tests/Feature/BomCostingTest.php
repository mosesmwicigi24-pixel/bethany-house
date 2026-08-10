<?php

namespace Tests\Feature;

use App\Models\BillOfMaterial;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BOM costing must read materials.unit_cost (the real column) — not the legacy
 * cost_per_unit name, which only resolves through a backward-compat accessor
 * and silently yields 0 anywhere the accessor isn't in play.
 */
class BomCostingTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        return $user;
    }

    private function costedBom(): array
    {
        $product = Product::factory()->create();

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'MAT-COST-1', 'name' => 'Costed Fabric', 'unit_of_measure' => 'm',
            'unit_cost' => 150.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bomId = DB::table('bills_of_materials')->insertGetId([
            'product_id' => $product->id, 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bom_items')->insert([
            'bom_id' => $bomId, 'material_id' => $materialId, 'quantity' => 2,
            'unit_of_measure' => 'm', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $bomId];
    }

    public function test_model_total_cost_is_non_zero_for_a_costed_material(): void
    {
        [, $bomId] = $this->costedBom();

        $bom = BillOfMaterial::with('items.material')->findOrFail($bomId);

        // 2 m × KES 150.00 = 300.00
        $this->assertEqualsWithDelta(300.00, (float) $bom->getTotalCost(), 0.001);
    }

    public function test_bom_api_returns_non_zero_line_and_total_cost(): void
    {
        $this->actor();
        [$product, $bomId] = $this->costedBom();

        $response = $this->getJson("/api/v1/admin/products/{$product->id}/bom/{$bomId}")
            ->assertOk();

        $bom = $response->json('bom');
        $this->assertEqualsWithDelta(300.00, $bom['total_cost'], 0.001);
        $this->assertEqualsWithDelta(300.00, $bom['items'][0]['line_cost'], 0.001);
        $this->assertEqualsWithDelta(150.00, $bom['items'][0]['material']['cost_per_unit'], 0.001);
    }
}
