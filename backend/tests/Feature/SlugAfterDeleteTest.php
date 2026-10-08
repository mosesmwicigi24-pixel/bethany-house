<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Owner, 2026-10-06: after deleting "Pectoral Cross" (#103, slug
 * pectoral-cross) a new product of the same name failed with "Failed to create
 * product." — generateSlug ignored soft-deleted rows, picked the slug the
 * deleted product still holds, and the unique index refused it. Deleted rows
 * keep their slug (so they can be restored), so a new one takes the next free.
 */
class SlugAfterDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): void
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_a_new_product_can_take_the_name_of_a_deleted_one(): void
    {
        $this->staff(['products.view', 'products.create', 'products.edit']);
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1.0, 'is_base' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $old = Product::factory()->create(['slug' => 'pectoral-cross', 'sku' => 'SF-PCX-01']);
        $old->delete();

        $res = $this->postJson('/api/v1/admin/products', [
            'sku'          => 'VES-PC-0023',
            'product_type' => 'simple',
            'status'       => 'draft',
            'brand'        => 'BETHANY HOUSE',
            'tax_class'    => 'standard',      // the console always sends it (the column is NOT NULL)
            'translations' => [['language_code' => 'en', 'name' => 'Pectoral Cross',
                                'description' => 'Pectoral Cross']],
            'prices'       => [['currency_code' => 'KES', 'regular_price' => 4000]],
        ]);

        $res->assertSuccessful();
        $new = Product::where('sku', 'VES-PC-0023')->firstOrFail();
        $this->assertSame('pectoral-cross-1', $new->slug);
        // the deleted one keeps its slug, so restoring it still works
        $this->assertSame('pectoral-cross', Product::withTrashed()->find($old->id)->slug);
    }

    public function test_a_new_category_can_take_the_name_of_a_deleted_one(): void
    {
        $old = new Category();
        $old->forceFill(['slug' => 'vestments', 'sort_order' => 0, 'is_active' => true])->save();
        $old->delete();

        $slug = (fn (string $b) => $this->generateSlug($b))->call(new \App\Http\Controllers\Api\CategoryController(), 'Vestments');

        $this->assertSame('vestments-1', $slug);
    }
}
