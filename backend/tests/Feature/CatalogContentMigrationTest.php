<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The 2026_30_07 catalog data migrations run against an empty test database
 * (no-op) during RefreshDatabase; these tests re-run their up() against
 * products created to mirror live rows, proving the enrichment, the slug
 * renames, the redirect fallbacks and the backup snapshots all behave.
 */
class CatalogContentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(string $file): void
    {
        $migration = include database_path("migrations/{$file}");
        $migration->up();
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
            'product_id'        => $product->id,
            'language_code'     => 'en',
            'name'              => $name,
            'short_description' => 'old short',
            'description'       => '<p>old description</p>',
        ]);

        return $product->fresh();
    }

    public function test_enrichment_migration_fills_content_and_backs_up_old_values(): void
    {
        $product = $this->makeProduct('SF-CHL-01', 'chalice-royale', 'Golden Chalice Cup with Paten Set');

        $this->runMigration('2026_30_07_000001_enrich_product_catalog_content.php');

        $t = DB::table('product_translations')
            ->where('product_id', $product->id)->where('language_code', 'en')->first();
        $this->assertNotSame('old short', $t->short_description);
        $this->assertNotSame('<p>old description</p>', $t->description);
        $this->assertStringContainsString('<p>', $t->description);

        $fresh = $product->fresh();
        $this->assertNotEmpty($fresh->features);
        $this->assertNotEmpty($fresh->features[0]['text']);
        $this->assertNotEmpty($fresh->aliases);

        $seo = DB::table('product_seo')
            ->where('product_id', $product->id)->where('language_code', 'en')->first();
        $this->assertNotNull($seo);
        $this->assertNotEmpty($seo->meta_title);
        $this->assertLessThanOrEqual(58, mb_strlen($seo->meta_title));
        $this->assertLessThanOrEqual(158, mb_strlen($seo->meta_description));

        // Old values snapshotted for rollback.
        $backup = DB::table('product_content_backup_20260730')->where('product_id', $product->id)->first();
        $this->assertSame('old short', $backup->old_short_description);

        // Re-run is idempotent: still exactly one backup row, content stable.
        $this->runMigration('2026_30_07_000001_enrich_product_catalog_content.php');
        $this->assertSame(1, DB::table('product_content_backup_20260730')->where('product_id', $product->id)->count());
    }

    public function test_slug_migration_renames_and_leaves_redirects(): void
    {
        $mitre = $this->makeProduct('CVB-0010', 'the-carry-along-bible', 'Mitre');
        // A swap pair: the oil vacates white-cassock, the cassock then claims it.
        $oil     = $this->makeProduct('FBU30QUE0-499E', 'white-cassock', 'Eliad Anointing Oil');
        $cassock = $this->makeProduct('FBU30QUE0', 'white-cassock-fbu30que0', 'White Cassock');

        $this->runMigration('2026_30_07_000002_fix_product_slugs.php');

        $this->assertSame('mitre', $mitre->fresh()->slug);
        $this->assertSame('eliad-anointing-oil', $oil->fresh()->slug);
        $this->assertSame('white-cassock', $cassock->fresh()->slug);

        // Dead old slug → redirect row to the renamed product.
        $this->assertSame(
            $mitre->id,
            (int) DB::table('product_slug_redirects')->where('old_slug', 'the-carry-along-bible')->value('product_id'),
        );
        // Self-healed slug (white-cassock is live again, owned by the right
        // product) must NOT have a redirect row hijacking it.
        $this->assertDatabaseMissing('product_slug_redirects', ['old_slug' => 'white-cassock']);
    }

    public function test_show_resolves_old_slug_through_redirects(): void
    {
        $mitre = $this->makeProduct('CVB-0010', 'the-carry-along-bible', 'Mitre');
        $this->runMigration('2026_30_07_000002_fix_product_slugs.php');

        $res = $this->getJson('/api/v1/products/the-carry-along-bible')->assertOk();
        $this->assertSame('mitre', $res->json('product.slug'));
        $this->assertSame('the-carry-along-bible', $res->json('product.redirected_from'));

        // The canonical slug serves normally, without the redirect marker.
        $direct = $this->getJson('/api/v1/products/mitre')->assertOk();
        $this->assertNull($direct->json('product.redirected_from'));
    }

    public function test_checkout_remaps_old_cart_slugs_before_validation(): void
    {
        $this->makeProduct('CVB-0010', 'the-carry-along-bible', 'Mitre');
        $this->runMigration('2026_30_07_000002_fix_product_slugs.php');

        // Deliberately omit customer/delivery fields: if the old slug had NOT
        // been remapped, exists:products,slug would list items.0.slug among the
        // errors. Its absence proves the pre-validation remap ran.
        $res = $this->postJson('/api/v1/storefront/orders', [
            'items' => [['slug' => 'the-carry-along-bible', 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertArrayNotHasKey('items.0.slug', $res->json('errors'));
    }
}
