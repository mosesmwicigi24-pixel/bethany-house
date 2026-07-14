<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bulk "Generate Variants from Attributes" saves one variant per request with an
 * auto-generated SKU. A duplicate SKU (two attribute values sharing a 3-letter
 * abbreviation, or a combo already stocked) must not fail the save — the server
 * resolves it to the next free SKU (…-MIN → …-MIN-2 → -3).
 */
class VariantSkuAutoIncrementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insert([
            'code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('products.edit', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function postVariant(Product $product, string $sku): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'sku'          => $sku,
            'variant_name' => 'Grey / Mint Cotton',
            'attributes'   => ['colour' => 'Grey', 'fabric' => 'Mint Cotton'],
            'prices'       => [['currency_code' => 'KES', 'regular_price' => 1500]],
        ]);
    }

    public function test_a_colliding_variant_sku_is_auto_incremented(): void
    {
        $product = Product::factory()->create();

        $this->postVariant($product, 'CLE-SC-001-GRE-MIN')
            ->assertStatus(201)->assertJsonPath('variant.sku', 'CLE-SC-001-GRE-MIN');

        // Same SKU again → resolved to -2 instead of "sku already taken".
        $this->postVariant($product, 'CLE-SC-001-GRE-MIN')
            ->assertStatus(201)->assertJsonPath('variant.sku', 'CLE-SC-001-GRE-MIN-2');

        // And again → -3.
        $this->postVariant($product, 'CLE-SC-001-GRE-MIN')
            ->assertStatus(201)->assertJsonPath('variant.sku', 'CLE-SC-001-GRE-MIN-3');

        $skus = ProductVariant::pluck('sku')->sort()->values()->all();
        $this->assertSame(
            ['CLE-SC-001-GRE-MIN', 'CLE-SC-001-GRE-MIN-2', 'CLE-SC-001-GRE-MIN-3'],
            $skus,
        );
    }

    public function test_a_unique_sku_is_stored_unchanged_and_uppercased(): void
    {
        $product = Product::factory()->create();

        $this->postVariant($product, 'cle-sc-001-gre-sel')
            ->assertStatus(201)->assertJsonPath('variant.sku', 'CLE-SC-001-GRE-SEL');
    }
}
