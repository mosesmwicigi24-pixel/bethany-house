<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/storefront/insights — the storefront's public read of the
 * basket mining behind the till's suggestions (see AttachRatesTest for the
 * arithmetic itself).
 *
 * The promises pinned here:
 *  - Public, no login: the storefront calls it like the product catalogue.
 *  - Same thresholds as the till: a pair below support 3 never surfaces.
 *  - Slugs only — the payload carries no id, name, count, rate or amount.
 *  - Published products only, on both sides of a pair and in `popular`.
 *  - Sales truth only: cancelled orders and unconverted quotations do not
 *    make a product "bought".
 *  - Advisory: no sales yet is an ordinary empty answer, never an error.
 */
class StorefrontInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** A product the public catalogue shows (or, unpublished, does not). */
    private function product(string $slug, bool $published = true): Product
    {
        return Product::factory()->create([
            'slug'         => $slug,
            'status'       => $published ? Product::STATUS_ACTIVE : Product::STATUS_INACTIVE,
            'published_at' => $published ? now()->subMonth() : null,
        ]);
    }

    /** One basket: an order holding the given products, placed $daysAgo days ago. */
    private function basket(array $products, int $daysAgo = 10, array $orderAttrs = []): Order
    {
        $when  = now()->subDays($daysAgo);
        $order = Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => 1000 * count($products),
            'currency_code' => 'KES',
            'created_at' => $when,
        ], $orderAttrs));

        foreach ($products as $product) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id,
                'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
                'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $order;
    }

    /**
     * 20 baskets, the AttachRatesTest shape:
     *   6 x {wine, wafers}   2 x {wine}   2 x {wafers}
     *   10 x {bible}  (2 of those also carry a candle — support 2, below the gate)
     */
    private function seedBaskets(): array
    {
        $wine   = $this->product('altar-wine');
        $wafers = $this->product('communion-wafers');
        $bible  = $this->product('study-bible');
        $candle = $this->product('altar-candle');

        for ($i = 0; $i < 6; $i++) {
            $this->basket([$wine, $wafers]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->basket([$wine]);
            $this->basket([$wafers]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->basket($i < 2 ? [$bible, $candle] : [$bible]);
        }

        return [$wine, $wafers, $bible, $candle];
    }

    public function test_feed_is_public_and_pairs_what_real_baskets_paired(): void
    {
        $this->seedBaskets();

        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        // Wine and wafers shared 6 of their 8 baskets each: paired both ways.
        $this->assertSame(['communion-wafers'], $res->json('data.bought_together.altar-wine'));
        $this->assertSame(['altar-wine'], $res->json('data.bought_together.communion-wafers'));

        // The bible's only companion co-occurred twice — below the support
        // gate the till uses — so neither side of that pair is claimed.
        $this->assertArrayNotHasKey('study-bible', $res->json('data.bought_together'));
        $this->assertArrayNotHasKey('altar-candle', $res->json('data.bought_together'));

        // Most-bought first: bible (10 baskets), then the two 8-basket
        // products. The candle (2 baskets) is below minimum support.
        $popular = $res->json('data.popular');
        $this->assertSame('study-bible', $popular[0]);
        $this->assertEqualsCanonicalizing(['altar-wine', 'communion-wafers'], array_slice($popular, 1));

        $this->assertSame(180, $res->json('meta.window_days'));
        $this->assertNotNull($res->json('meta.generated_at'));
    }

    public function test_feed_carries_slugs_only_no_ids_counts_or_money(): void
    {
        [$wine] = $this->seedBaskets();

        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($res->json()));
        $this->assertSame(['bought_together', 'popular'], array_keys($res->json('data')));
        $this->assertSame(['window_days', 'generated_at'], array_keys($res->json('meta')));

        // Every leaf under data is a product slug string — nothing numeric.
        $leaves = [];
        $data = $res->json('data');
        array_walk_recursive($data, function ($v) use (&$leaves) { $leaves[] = $v; });
        $this->assertNotEmpty($leaves);
        foreach ($leaves as $leaf) {
            $this->assertIsString($leaf);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $leaf);
        }
        $this->assertStringNotContainsString($wine->sku, $res->getContent());
    }

    public function test_unpublished_products_never_appear(): void
    {
        $wine   = $this->product('altar-wine');
        $hidden = $this->product('retired-cups', published: false);
        $bible  = $this->product('study-bible');

        // The hidden product is the wine's strongest companion and a best
        // seller in its own right — and still must not be named.
        for ($i = 0; $i < 6; $i++) {
            $this->basket([$wine, $hidden]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->basket([$bible]);
        }

        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        $this->assertStringNotContainsString('retired-cups', $res->getContent());
        $this->assertSame(['altar-wine', 'study-bible'], collect($res->json('data.popular'))->sort()->values()->all());
        $this->assertSame([], $res->json('data.bought_together'));
    }

    public function test_cancelled_orders_and_unconverted_quotes_do_not_count(): void
    {
        $wine   = $this->product('altar-wine');
        $wafers = $this->product('communion-wafers');

        // Six cancelled orders and six drafts that were never paid or
        // confirmed (an open quotation's shape): pipeline, not purchases.
        for ($i = 0; $i < 6; $i++) {
            $this->basket([$wine, $wafers], 10, ['status' => 'cancelled']);
            $this->basket([$wine, $wafers], 10, ['status' => 'pending', 'payment_status' => 'pending']);
        }

        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        $this->assertSame([], $res->json('data.popular'));
        $this->assertSame([], $res->json('data.bought_together'));
    }

    public function test_no_sales_is_an_ordinary_empty_answer(): void
    {
        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        $this->assertSame([], $res->json('data.popular'));
        $this->assertSame([], $res->json('data.bought_together'));
    }

    public function test_companions_are_capped_and_ordered_strongest_first(): void
    {
        $anchor = $this->product('chalice');
        $filler = $this->product('study-bible');
        $companions = [];
        // Seven companions; companion n shares (9 - n) baskets with the anchor.
        for ($n = 0; $n < 7; $n++) {
            $companions[$n] = $this->product("companion-{$n}");
        }
        for ($b = 0; $b < 9; $b++) {
            $lines = [$anchor];
            foreach ($companions as $n => $c) {
                if ($b < 9 - $n) {
                    $lines[] = $c;
                }
            }
            $this->basket($lines);
        }
        // Unrelated baskets keep every companion's lift above the 1.2 gate.
        for ($i = 0; $i < 20; $i++) {
            $this->basket([$filler]);
        }

        $res = $this->getJson('/api/v1/storefront/insights')->assertOk();

        $this->assertSame(
            ['companion-0', 'companion-1', 'companion-2', 'companion-3', 'companion-4'],
            $res->json('data.bought_together.chalice'),
        );
    }
}
