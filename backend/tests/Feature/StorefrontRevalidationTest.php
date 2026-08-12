<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Services\Storefront\StorefrontRevalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The storefront renders the catalogue over ISR with a 300s window, so a
 * product edit is invisible until the hub tells it otherwise. These tests pin
 * that contract: an image upload pings, the signature is one the storefront
 * will accept, and a storefront that is down or unconfigured never costs the
 * owner a save.
 */
class StorefrontRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-storefront-secret';

    private function configure(): void
    {
        config([
            'services.storefront.url'               => 'https://storefront.test',
            'services.storefront.revalidate_secret' => self::SECRET,
        ]);
    }

    /** The debounce is cache state that leaks between tests otherwise. */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_uploading_a_product_image_pings_the_storefront(): void
    {
        $this->configure();

        // Fake only AFTER the product exists and the debounce is clear.
        // Faking earlier records the product insert's own ping, and then this
        // passes even when ProductImage is not observed at all — which is the
        // one thing it is here to prove.
        $product = Product::factory()->create();
        Cache::flush();
        Http::fake(['storefront.test/*' => Http::response(['revalidated' => true], 200)]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url'  => 'https://hub.test/storage/products/1/' . fake()->uuid() . '.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://storefront.test/api/revalidate'
            && $r->method() === 'POST'
            && $r->data() === ['tag' => 'catalog']);
    }

    public function test_the_signature_is_hmac_of_the_exact_body_the_storefront_will_verify(): void
    {
        $this->configure();
        Http::fake(['storefront.test/*' => Http::response([], 200)]);

        StorefrontRevalidator::catalogChanged();

        Http::assertSent(function (ClientRequest $r) {
            $expected = 'sha256=' . hash_hmac('sha256', $r->body(), self::SECRET);

            return $r->header('X-Storefront-Signature')[0] === $expected;
        });
    }

    public function test_a_price_edit_also_pings(): void
    {
        $this->configure();

        // Fake AFTER the product exists: creating it is itself a catalogue
        // change, and counting its ping would make this assertion a lie.
        $product = Product::factory()->create();
        Cache::flush();
        Http::fake(['storefront.test/*' => Http::response([], 200)]);

        ProductPrice::create([
            'product_id'    => $product->id,
            'currency_code' => 'KES',
            'regular_price' => 1500,
        ]);

        Http::assertSentCount(1);
    }

    public function test_a_burst_of_rows_in_one_save_sends_a_single_ping(): void
    {
        $this->configure();

        $product = Product::factory()->create();
        Cache::flush();
        Http::fake(['storefront.test/*' => Http::response([], 200)]);

        // Freeze the clock so this asserts the debounce, not how fast the
        // machine running the suite can do three inserts.
        $this->freezeTime();

        for ($i = 1; $i <= 3; $i++) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_url'  => "https://hub.test/storage/products/1/img{$i}.jpg",
                'is_primary' => $i === 1,
                'sort_order' => $i,
            ]);
        }

        Http::assertSentCount(1);
    }

    public function test_it_is_inert_until_the_secret_is_configured(): void
    {
        config([
            'services.storefront.url'               => 'https://storefront.test',
            'services.storefront.revalidate_secret' => null,
        ]);
        Http::fake();

        StorefrontRevalidator::catalogChanged();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_storefront_never_breaks_the_save(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $product = Product::factory()->create();

        // The upload must still succeed and the row must still be there.
        $image = ProductImage::create([
            'product_id' => $product->id,
            'image_url'  => 'https://hub.test/storage/products/1/a.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_deleting_an_image_pings_too(): void
    {
        $this->configure();

        $product = Product::factory()->create();
        $image   = ProductImage::create([
            'product_id' => $product->id,
            'image_url'  => 'https://hub.test/storage/products/1/a.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        Cache::flush();
        Http::fake(['storefront.test/*' => Http::response([], 200)]);

        $image->delete();

        Http::assertSentCount(1);
    }
}
