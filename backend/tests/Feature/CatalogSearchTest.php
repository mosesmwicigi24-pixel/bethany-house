<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widened public catalog search: every query token must match at least one of
 * translated name / description / short_description / sku / aliases JSON, so
 * buyer phrasing like "clergy robe" or "children bible" finds products whose
 * words are split across fields. Also covers the two fixed bugs: the /search
 * route being shadowed by /{slug}, and draft products leaking via SKU match.
 */
class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = [], array $translation = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ], $overrides));

        ProductTranslation::create(array_merge([
            'product_id'    => $product->id,
            'language_code' => 'en',
            'name'          => 'Test ' . $product->sku,
        ], $translation));

        return $product->fresh();
    }

    public function test_search_matches_aliases(): void
    {
        $this->makeProduct(
            ['aliases' => ['soutane', 'clergy robe', 'kanzu']],
            ['name' => 'White Cassock'],
        );
        $this->makeProduct([], ['name' => 'Brass Chalice']);

        $res = $this->getJson('/api/v1/products?search=soutane')->assertOk();
        $this->assertSame(1, $res->json('total'));
        $this->assertSame('White Cassock', $res->json('data.0.translations.0.name'));
    }

    public function test_multi_word_query_matches_across_fields(): void
    {
        // "clergy" only in aliases, "robe" only in the description — the old
        // single-phrase ILIKE found nothing for "clergy robe".
        $this->makeProduct(
            ['aliases' => ['clergy wear']],
            ['name' => 'White Cassock', 'description' => 'A traditional robe for ministers.'],
        );

        $res = $this->getJson('/api/v1/products?search=' . urlencode('clergy robe'))->assertOk();
        $this->assertSame(1, $res->json('total'));
    }

    public function test_short_description_is_searchable(): void
    {
        $this->makeProduct([], [
            'name'              => 'Aluminium Tray',
            'short_description' => 'Serving tray that holds forty communion cups',
        ]);

        $res = $this->getJson('/api/v1/products?search=forty')->assertOk();
        $this->assertSame(1, $res->json('total'));
    }

    public function test_every_token_must_match_something(): void
    {
        $this->makeProduct([], ['name' => 'Altar Bell']);

        // "altar" matches, "spaceship" doesn't → no result (token-AND).
        $res = $this->getJson('/api/v1/products?search=' . urlencode('altar spaceship'))->assertOk();
        $this->assertSame(0, $res->json('total'));
    }

    public function test_draft_products_no_longer_leak_via_sku(): void
    {
        $this->makeProduct(
            ['status' => 'draft', 'published_at' => null, 'sku' => 'SECRET-DRAFT-01'],
            ['name' => 'Unreleased Item'],
        );

        $res = $this->getJson('/api/v1/products?search=SECRET-DRAFT')->assertOk();
        $this->assertSame(0, $res->json('total'));
    }

    public function test_search_route_is_not_shadowed_by_slug(): void
    {
        $this->makeProduct(['aliases' => ['thurible', 'censer']], ['name' => 'Incense Burner']);

        // Used to 404: /{slug} captured "search" before the route was reachable.
        $res = $this->getJson('/api/v1/products/search?q=thurible')->assertOk();
        $this->assertCount(1, $res->json('data'));
    }
}
