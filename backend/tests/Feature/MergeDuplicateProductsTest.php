<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The duplicate-product merge (2026_30_07_000003): survivors absorb images,
 * variants and aliases; losers are archived (never deleted) and their slugs
 * redirect to the survivor. Runs the migration against mirrored rows since the
 * CI database has no live catalog.
 */
class MergeDuplicateProductsTest extends TestCase
{
    use RefreshDatabase;

    private function runMerge(): void
    {
        $m = include database_path('migrations/2026_30_07_000003_merge_duplicate_products.php');
        $m->up();
    }

    private function makeProduct(string $sku, string $slug, string $name, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'sku'          => $sku,
            'slug'         => $slug,
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ], $overrides));
        ProductTranslation::create([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
        ]);
        return $product->fresh();
    }

    public function test_bread_merge_copies_images_deactivates_junk_variants_and_archives(): void
    {
        $survivor = $this->makeProduct('CWIZIB1ZL', 'communion-wafer-bread-200pcs', 'Communion Wafer Bread 200PCs', [
            'aliases' => ['communion wafers'],
        ]);
        DB::table('product_variants')->insert([
            'product_id' => $survivor->id, 'sku' => 'CWIZIB1ZL-V1',
            'variant_name' => 'Communion Bread 250Pcs', 'attributes' => json_encode([]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loser = $this->makeProduct('GEN-PO-001', '200-pcs-of-bread', '200 pcs of Bread', [
            'aliases' => ['wafer bread'],
        ]);
        DB::table('product_images')->insert([
            'product_id' => $loser->id, 'image_url' => 'https://x/bread.jpg',
            'is_primary' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMerge();

        $this->assertSame('archived', $loser->fresh()->status);
        // Image travelled, arrives non-primary.
        $this->assertDatabaseHas('product_images', [
            'product_id' => $survivor->id, 'image_url' => 'https://x/bread.jpg', 'is_primary' => false,
        ]);
        // Junk 250Pcs variant silenced.
        $this->assertDatabaseHas('product_variants', ['sku' => 'CWIZIB1ZL-V1', 'is_active' => false]);
        // Aliases unioned; old URL redirects to the survivor.
        $this->assertContains('wafer bread', $survivor->fresh()->aliases);
        $this->assertSame($survivor->id,
            (int) DB::table('product_slug_redirects')->where('old_slug', '200-pcs-of-bread')->value('product_id'));
    }

    public function test_canon_gown_survivor_takes_the_clean_slug(): void
    {
        $loser    = $this->makeProduct('CLE-S-001', 'canon-gown', 'Canon Gown');
        $survivor = $this->makeProduct('M3BXRUQJC', 'canon-gown-catholic', 'Canon Gown');

        $this->runMerge();

        $this->assertSame('archived', $loser->fresh()->status);
        $this->assertSame('canon-gown-archived', $loser->fresh()->slug);
        $this->assertSame('canon-gown', $survivor->fresh()->slug);
        // Old survivor slug redirects; the reclaimed live slug must not.
        $this->assertSame($survivor->id,
            (int) DB::table('product_slug_redirects')->where('old_slug', 'canon-gown-catholic')->value('product_id'));
        $this->assertDatabaseMissing('product_slug_redirects', ['old_slug' => 'canon-gown']);
    }

    public function test_cassock_merge_copies_only_missing_variants_with_prices(): void
    {
        $survivor = $this->makeProduct('CLE-PCB-001', 'white-princes-cassock', 'White Princes Cassock');
        DB::table('product_variants')->insert([
            'product_id' => $survivor->id, 'sku' => 'PCB-V1',
            'variant_name' => 'White Pleats', 'attributes' => json_encode(['pleats' => 'white']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loser = $this->makeProduct('CLE-PC-001', 'princes-cassock', 'Princes Cassock - White');
        // One duplicate (skipped), one unique (copied, with its price).
        DB::table('product_variants')->insert([
            'product_id' => $loser->id, 'sku' => 'PC-V1',
            'variant_name' => 'White Pleats', 'attributes' => json_encode(['pleats' => 'white']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uniqueId = DB::table('product_variants')->insertGetId([
            'product_id' => $loser->id, 'sku' => 'PC-V2',
            'variant_name' => 'Green Pleats, Self Print', 'attributes' => json_encode(['pleats' => 'green']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $loser->id, 'product_variant_id' => $uniqueId,
            'currency_code' => 'KES', 'regular_price' => 13000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMerge();

        $this->assertSame('archived', $loser->fresh()->status);
        // Unique variant copied under a -M sku, price attached to the copy.
        $copy = DB::table('product_variants')
            ->where('product_id', $survivor->id)->where('sku', 'PC-V2-M')->first();
        $this->assertNotNull($copy);
        $this->assertDatabaseHas('product_prices', [
            'product_variant_id' => $copy->id, 'currency_code' => 'KES', 'regular_price' => 13000,
        ]);
        // The duplicate name was NOT copied.
        $this->assertSame(0, DB::table('product_variants')
            ->where('product_id', $survivor->id)->where('sku', 'PC-V1-M')->count());
        // Archived loser is gone from the public catalog; slug redirects.
        $this->assertSame(0, Product::published()->where('id', $loser->id)->count());
        $this->assertSame($survivor->id,
            (int) DB::table('product_slug_redirects')->where('old_slug', 'princes-cassock')->value('product_id'));
    }
}
