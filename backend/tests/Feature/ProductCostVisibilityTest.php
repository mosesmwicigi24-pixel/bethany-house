<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
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
 * Phase 1C, item 1 — what a product costs us is need-to-know.
 *
 * Owner's field rule: product and production cost are for super_admin, admin,
 * finance_manager and the procurement roles. An outlet manager can open a
 * product and its bill of materials (products.view, production.view_bom) to
 * run the shop and the floor, but must not read cost_price, a material's unit
 * cost, a BOM line cost or its total.
 */
class ProductCostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function costedProduct(): Product
    {
        $product = Product::factory()->create();
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => 5000, 'cost_price' => 3187.43,
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'currency_code' => 'KES', 'regular_price' => 5200, 'cost_price' => 3291.67,
        ]);

        return $product;
    }

    private function costedBom(Product $product): int
    {
        $materialId = DB::table('materials')->insertGetId([
            'code' => 'MAT-VC-1', 'name' => 'Wool Crepe', 'unit_of_measure' => 'm',
            'unit_cost' => 870.00, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bomId = DB::table('bills_of_materials')->insertGetId([
            'product_id' => $product->id, 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bom_items')->insert([
            'bom_id' => $bomId, 'material_id' => $materialId, 'quantity' => 3,
            'unit_of_measure' => 'm', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $bomId;
    }

    // ── The grant ────────────────────────────────────────────────────────────

    public function test_the_permission_goes_to_exactly_the_roles_the_owner_named(): void
    {
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'products.view_cost'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(
            // accountant added in Phase 2 (the ledger operator reads cost).
            ['accountant', 'admin', 'finance_manager', 'procurement_manager', 'procurement_officer'],
            $holders,
        );
    }

    // ── Product detail ───────────────────────────────────────────────────────

    public function test_an_outlet_manager_reads_the_product_but_not_its_cost(): void
    {
        $product = $this->costedProduct();
        $this->actAs('outlet_manager');

        $res = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk();

        $this->assertNotEmpty($res->json('product.prices'));
        $this->assertSame(5000.0, (float) $res->json('product.prices.0.regular_price'),
            'the selling price is still there — only the cost goes');
        $this->assertStringNotContainsString('cost_price', $res->getContent());
        // Costs with decimals: a random SKU or UUID can never contain one (it
        // once failed on a generated "VAR-73100" when the cost was 3100).
        $this->assertStringNotContainsString('3187.43', $res->getContent());
        $this->assertStringNotContainsString('3291.67', $res->getContent());
    }

    public function test_procurement_still_reads_the_cost(): void
    {
        $product = $this->costedProduct();
        $this->actAs('procurement_officer');

        $res = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk();

        $this->assertSame(3187.43, (float) $res->json('product.prices.0.cost_price'));
        $this->assertSame(3291.67, (float) $res->json('product.variants.0.prices.0.cost_price'));
    }

    public function test_admin_still_reads_the_cost(): void
    {
        $product = $this->costedProduct();
        $this->actAs('admin');

        $this->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.prices.0.cost_price', fn ($v) => (float) $v === 3187.43);
    }

    public function test_the_list_carries_no_cost_for_anyone_to_strip(): void
    {
        // Asserted so a future column added to formatListItem is noticed.
        $this->costedProduct();
        $this->actAs('outlet_manager');

        $res = $this->getJson('/api/v1/admin/products')->assertOk();
        $this->assertStringNotContainsString('cost_price', $res->getContent());
    }

    // ── Bill of materials ────────────────────────────────────────────────────

    public function test_an_outlet_manager_reads_the_bom_quantities_but_not_its_costs(): void
    {
        $product = $this->costedProduct();
        $bomId   = $this->costedBom($product);
        $this->actAs('outlet_manager');

        foreach ([
            "/api/v1/admin/products/{$product->id}/bom",
            "/api/v1/admin/products/{$product->id}/bom/{$bomId}",
        ] as $url) {
            $res  = $this->getJson($url)->assertOk();
            $body = $res->getContent();

            $this->assertStringContainsString('Wool Crepe', $body, 'materials and quantities stay');
            foreach (['cost_per_unit', 'line_cost', 'total_cost', 'unit_cost', '870', '2610'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "{$url} leaked {$needle}");
            }
        }
    }

    public function test_procurement_still_costs_the_bom(): void
    {
        $product = $this->costedProduct();
        $bomId   = $this->costedBom($product);
        $this->actAs('procurement_manager');

        $bom = $this->getJson("/api/v1/admin/products/{$product->id}/bom/{$bomId}")
            ->assertOk()->json('bom');

        $this->assertEqualsWithDelta(2610.0, $bom['total_cost'], 0.001);
        $this->assertEqualsWithDelta(870.0, $bom['items'][0]['material']['cost_per_unit'], 0.001);
    }

    // ── A cost-blind editor must not wipe the cost it cannot see ─────────────

    public function test_an_editor_without_the_cost_permission_does_not_erase_it(): void
    {
        $product = $this->costedProduct();

        // A custom role: may edit the catalogue, may not see cost. Its form
        // has no cost field, so a save that wrote what it sent would null
        // cost_price on every row it touched.
        $user = User::factory()->create();
        foreach (['products.view', 'products.edit'] as $p) {
            $user->givePermissionTo(Permission::findByName($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        $this->putJson("/api/v1/admin/products/{$product->id}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => 5500]],
        ])->assertOk();

        $row = ProductPrice::where('product_id', $product->id)->whereNull('product_variant_id')->first();
        $this->assertSame(5500.0, (float) $row->regular_price);
        $this->assertSame(3187.43, (float) $row->cost_price, 'cost survives a cost-blind save');
    }

    // ── The migration makes it live without waiting for permission:sync ──────

    public function test_the_migration_grants_it_to_the_named_roles(): void
    {
        DB::table('role_has_permissions')
            ->where('permission_id', Permission::findByName('products.view_cost', 'sanctum')->id)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_10_03_300001_add_products_view_cost_permission.php');
        $migration->up();

        foreach (['admin', 'finance_manager', 'procurement_manager', 'procurement_officer'] as $role) {
            $this->assertTrue(Role::findByName($role, 'sanctum')->hasPermissionTo('products.view_cost'), $role);
        }
        foreach (['outlet_manager', 'pos_clerk', 'tailor'] as $role) {
            $this->assertFalse(Role::findByName($role, 'sanctum')->hasPermissionTo('products.view_cost'), $role);
        }
    }

    public function test_the_migration_rolls_back_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_03_300001_add_products_view_cost_permission.php');
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNull(Permission::where('name', 'products.view_cost')->first());

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(Role::findByName('procurement_officer', 'sanctum')->hasPermissionTo('products.view_cost'));
    }
}
