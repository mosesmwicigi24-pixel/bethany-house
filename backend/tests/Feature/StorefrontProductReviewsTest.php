<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET / POST /api/v1/storefront/products/{slug}/reviews — HUB_CONTRACT §8.
 *
 * The promises pinned here:
 *  - A product with nothing approved answers count 0 (the storefront's
 *    "show the fallback" switch), never an error; an unknown or unpublished
 *    slug is 404.
 *  - Only approved reviews are counted, averaged and listed. A pending one
 *    changes nothing until an administrator approves it.
 *  - Average and distribution are recomputed by the test from the rows it
 *    planted, not read back from the implementation's own arithmetic.
 *  - A customer's review is saved pending — never public on its own — with
 *    their words kept verbatim; the email is stored for verification and
 *    never returned.
 *  - A retried submission (same client_request_id) is one row, not two.
 *  - "Verified" means a recognised order for that email holds the product;
 *    a cancelled order does not count, and neither does another product.
 *  - The X-Storefront-Key gate covers both verbs once a key is configured.
 */
class StorefrontProductReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $slug): string
    {
        return "/api/v1/storefront/products/{$slug}/reviews";
    }

    /** A product the public catalogue shows (or, unpublished, does not). */
    private function product(string $slug = 'golden-chalice', bool $published = true): Product
    {
        return Product::factory()->create([
            'slug'         => $slug,
            'status'       => $published ? Product::STATUS_ACTIVE : Product::STATUS_INACTIVE,
            'published_at' => $published ? now()->subMonth() : null,
        ]);
    }

    /** One review row, approved unless told otherwise. */
    private function review(Product $product, array $attrs = []): ProductReview
    {
        return ProductReview::create(array_merge([
            'product_id'  => $product->id,
            'user_id'     => null,
            'author_name' => 'Rev. Canon Mwangi',
            'rating'      => 5,
            'is_approved' => true,
            'source'      => ProductReview::SOURCE_STOREFRONT,
        ], $attrs));
    }

    /** A sale (recognised order) for $email holding $product. */
    private function sale(Product $product, string $email, array $orderAttrs = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'status'         => 'completed',
            'payment_status' => 'paid',
            'customer_email' => $email,
            'currency_code'  => 'KES',
            'total_amount'   => 1000,
        ], $orderAttrs));
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $order;
    }

    public function test_a_product_with_no_approved_reviews_answers_count_zero(): void
    {
        $product = $this->product();
        $this->review($product, ['is_approved' => false]);   // pending: not public

        $this->getJson($this->url('golden-chalice'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'average'      => 0,
                'count'        => 0,
                'distribution' => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
                'reviews'      => [],
            ]]);
    }

    public function test_only_approved_reviews_are_summarised_and_listed(): void
    {
        $product = $this->product();
        $other   = $this->product('altar-wine');
        $staff   = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Wanjiru']);

        $planted = [5, 5, 4];                                  // the approved ratings
        $this->review($product, ['rating' => 5, 'helpful_count' => 3, 'title' => 'Worthy of the table',
            'review' => 'We ordered two sets for our jubilee.', 'created_at' => now()->subDays(9)]);
        $this->review($product, ['rating' => 5, 'author_name' => null, 'user_id' => $staff->id,
            'source' => ProductReview::SOURCE_HUB, 'created_at' => now()->subDays(2)]);
        $this->review($product, ['rating' => 4, 'author_name' => '', 'author_email' => 'secret@example.com',
            'created_at' => now()->subDay()]);
        $this->review($product, ['rating' => 1, 'is_approved' => false]);   // pending — invisible
        $this->review($other,   ['rating' => 2]);                           // another product

        $res = $this->getJson($this->url('golden-chalice'))->assertOk();
        $data = $res->json('data');

        // Independent arithmetic from the planted ratings.
        $this->assertSame(count($planted), $data['count']);
        $this->assertSame(round(array_sum($planted) / count($planted), 1), (float) $data['average']);
        $this->assertSame(['5' => 2, '4' => 1, '3' => 0, '2' => 0, '1' => 0], $data['distribution']);

        // Most helpful first, then newest; names as the public may see them.
        $this->assertSame(['Rev. Canon Mwangi', 'Customer', 'Grace Wanjiru'], array_column($data['reviews'], 'author'));
        $this->assertSame([5, 4, 5], array_column($data['reviews'], 'rating'));
        $this->assertSame('We ordered two sets for our jubilee.', $data['reviews'][0]['body']);
        $this->assertSame('published', $data['reviews'][0]['status']);
        $this->assertArrayNotHasKey('email', $data['reviews'][0]);
        $this->assertStringNotContainsString('secret@example.com', $res->getContent());
    }

    public function test_unknown_and_unpublished_slugs_are_not_found(): void
    {
        $this->product('draft-cope', published: false);

        $this->getJson($this->url('no-such-product'))->assertStatus(404);
        $this->getJson($this->url('draft-cope'))->assertStatus(404);
        $this->postJson($this->url('no-such-product'), ['rating' => 5])->assertStatus(404);
    }

    public function test_a_customer_review_is_saved_pending_and_only_published_once_approved(): void
    {
        $product = $this->product();

        $res = $this->postJson($this->url('golden-chalice'), [
            'client_request_id' => 'req-1',
            'rating'            => 4,
            'title'             => 'Beautiful finish',
            'body'              => "Arrived in two days. The paten fits perfectly.\nAsante.",
            'author'            => '  Rev. Mwangi  ',
            'email'             => 'Rev.Mwangi@Example.com',
        ])->assertStatus(201);

        $res->assertJsonPath('review.rating', 4)
            ->assertJsonPath('review.author', 'Rev. Mwangi')
            ->assertJsonPath('review.status', 'pending')
            ->assertJsonPath('review.verified', false);
        $this->assertStringNotContainsString('@example.com', $res->getContent());

        $this->assertDatabaseHas('product_reviews', [
            'product_id'           => $product->id,
            'user_id'              => null,
            'author_name'          => 'Rev. Mwangi',
            'author_email'         => 'rev.mwangi@example.com',
            'client_request_id'    => 'req-1',
            'source'               => 'storefront',
            'rating'               => 4,
            'title'                => 'Beautiful finish',
            'review'               => "Arrived in two days. The paten fits perfectly.\nAsante.",
            'is_approved'          => false,
            'is_verified_purchase' => false,
        ]);

        // Pending: the public summary is unchanged …
        $this->getJson($this->url('golden-chalice'))->assertJsonPath('data.count', 0);

        // … until an administrator approves it (Customers → Reviews & Ratings).
        ProductReview::where('client_request_id', 'req-1')->update(['is_approved' => true]);
        $this->getJson($this->url('golden-chalice'))
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.reviews.0.author', 'Rev. Mwangi');
    }

    public function test_retrying_the_same_submission_saves_one_row(): void
    {
        $this->product();
        $body = ['client_request_id' => 'req-retry', 'rating' => 5, 'body' => 'Once only.'];

        $first  = $this->postJson($this->url('golden-chalice'), $body)->assertStatus(201);
        $second = $this->postJson($this->url('golden-chalice'), $body)->assertStatus(200);

        $this->assertSame($first->json('review.id'), $second->json('review.id'));
        $this->assertSame(1, ProductReview::count());
    }

    public function test_a_rating_must_be_one_to_five(): void
    {
        $this->product();

        $this->postJson($this->url('golden-chalice'), ['rating' => 0])->assertStatus(422);
        $this->postJson($this->url('golden-chalice'), ['rating' => 6])->assertStatus(422);
        $this->postJson($this->url('golden-chalice'), ['rating' => 'five'])->assertStatus(422);
        $this->postJson($this->url('golden-chalice'), ['body' => 'no stars'])->assertStatus(422);
        $this->postJson($this->url('golden-chalice'), ['rating' => 5, 'email' => 'not-an-email'])->assertStatus(422);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_verified_means_a_recognised_order_for_that_email_holds_the_product(): void
    {
        $product = $this->product();
        $other   = $this->product('altar-wine');

        $sale = $this->sale($product, 'Buyer@Church.org');                             // a real sale, mixed-case email
        $this->sale($product, 'cancelled@church.org', ['status' => 'cancelled', 'payment_status' => 'pending']);
        $this->sale($other,   'wine@church.org');                                       // bought something else

        $this->postJson($this->url('golden-chalice'), ['rating' => 5, 'email' => 'buyer@church.org'])
            ->assertStatus(201)->assertJsonPath('review.verified', true);
        $this->postJson($this->url('golden-chalice'), ['rating' => 5, 'email' => 'cancelled@church.org'])
            ->assertStatus(201)->assertJsonPath('review.verified', false);
        $this->postJson($this->url('golden-chalice'), ['rating' => 5, 'email' => 'wine@church.org'])
            ->assertStatus(201)->assertJsonPath('review.verified', false);
        $this->postJson($this->url('golden-chalice'), ['rating' => 5])
            ->assertStatus(201)->assertJsonPath('review.verified', false);

        $this->assertDatabaseHas('product_reviews', [
            'author_email' => 'buyer@church.org', 'is_verified_purchase' => true, 'order_id' => $sale->id,
        ]);
        $this->assertSame(1, ProductReview::where('is_verified_purchase', true)->count());
    }

    public function test_the_storefront_key_gates_both_verbs_once_configured(): void
    {
        $this->product();
        config(['services.storefront.key' => 'sekret']);

        $this->getJson($this->url('golden-chalice'))->assertStatus(401);
        $this->getJson($this->url('golden-chalice'), ['X-Storefront-Key' => 'wrong'])->assertStatus(401);
        $this->postJson($this->url('golden-chalice'), ['rating' => 5], ['X-Storefront-Key' => 'wrong'])->assertStatus(401);

        $this->getJson($this->url('golden-chalice'), ['X-Storefront-Key' => 'sekret'])->assertOk();
        $this->postJson($this->url('golden-chalice'), ['rating' => 5], ['X-Storefront-Key' => 'sekret'])->assertStatus(201);
    }
}
