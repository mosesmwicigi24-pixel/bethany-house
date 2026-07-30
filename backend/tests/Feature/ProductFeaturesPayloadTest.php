<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * formatDetail must expose features + aliases: the whole catalog was enriched
 * on 2026-07-30, but the detail formatter omitted both — the admin Features
 * tab loaded an empty list (and saving from that state would have wiped the
 * stored values). Also covers the owner-directed retirement of the standard
 * Chalice Cup Medium (migration 2026_30_07_000004).
 */
class ProductFeaturesPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $sku, string $slug, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'sku'          => $sku,
            'slug'         => $slug,
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ], $overrides));
        ProductTranslation::create([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Test ' . $sku,
        ]);
        return $product->fresh();
    }

    public function test_public_detail_exposes_features_and_aliases(): void
    {
        $this->makeProduct('FEAT-01', 'feature-product', [
            'features' => [['icon' => '✨', 'text' => 'Gold-plated brass finish']],
            'aliases'  => ['gilt chalice'],
        ]);

        $res = $this->getJson('/api/v1/products/feature-product')->assertOk();
        $this->assertSame('Gold-plated brass finish', $res->json('product.features.0.text'));
        $this->assertSame(['gilt chalice'], $res->json('product.aliases'));
    }

    public function test_detail_defaults_to_empty_arrays_not_null(): void
    {
        $this->makeProduct('FEAT-02', 'plain-product');

        $res = $this->getJson('/api/v1/products/plain-product')->assertOk();
        $this->assertSame([], $res->json('product.features'));
        $this->assertSame([], $res->json('product.aliases'));
    }

    public function test_standard_chalice_archived_with_redirect_to_survivor(): void
    {
        $survivor = $this->makeProduct('7IM6K8KA4', 'golden-chalice-cup-medium');
        $retire   = $this->makeProduct('7IM6K8KA4-D2C7', 'chalice-cup-medium');

        $m = include database_path('migrations/2026_30_07_000004_archive_standard_chalice_cup.php');
        $m->up();

        $this->assertSame('archived', $retire->fresh()->status);
        $this->assertSame('active', $survivor->fresh()->status);
        $this->assertSame($survivor->id,
            (int) DB::table('product_slug_redirects')->where('old_slug', 'chalice-cup-medium')->value('product_id'));

        // Old URL resolves to the survivor through the show() fallback.
        $res = $this->getJson('/api/v1/products/chalice-cup-medium')->assertOk();
        $this->assertSame('golden-chalice-cup-medium', $res->json('product.slug'));
    }
}
