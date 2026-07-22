<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Server-authoritative discount enforcement for the guest storefront:
 * an active promotion must both (a) show as a discounted sale_price in the
 * product API and (b) reduce the CHARGED order total — computed on the server.
 * The two must agree (display == charge), since both use PromotionService.
 */
class StorefrontDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProduct(array $prices = ['KES' => 1500, 'USD' => 14]): Product
    {
        $product = Product::factory()->create([
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ]);
        ProductTranslation::create([
            'product_id'    => $product->id,
            'language_code' => 'en',
            'name'          => 'Test ' . $product->sku,
        ]);
        foreach ($prices as $code => $amount) {
            ProductPrice::create([
                'product_id'    => $product->id,
                'currency_code' => $code,
                'regular_price' => $amount,
            ]);
        }
        return $product->fresh();
    }

    private function activePromotion(array $attrs = []): Promotion
    {
        return Promotion::create(array_merge([
            'name'           => 'Blessed Friday',
            'type'           => 'product_discount',
            'discount_type'  => 'percentage',
            'discount_value' => 20,
            'is_active'      => true,
            'starts_at'      => now()->subDay(),
            'ends_at'        => now()->addDay(),
        ], $attrs));
    }

    private function payload(Product $product, int $qty = 2): array
    {
        return [
            'customer'       => ['first_name' => 'Jane', 'last_name' => 'Mwangi', 'phone' => '+254712345678'],
            'delivery'       => ['method' => 'delivery', 'address' => 'Moi Ave', 'city' => 'Nairobi'],
            'payment_method' => 'mpesa',
            'country_code'   => 'KE',
            'items'          => [['slug' => $product->slug, 'quantity' => $qty]],
        ];
    }

    public function test_active_percentage_promotion_is_charged_at_checkout(): void
    {
        $product = $this->makeProduct(['KES' => 1500]);
        $this->activePromotion(['discount_value' => 20]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, 2))->assertStatus(201);
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();

        // 2 × KES 1,500 = 3,000 list; 20% off → 2,400 charged; 600 saved.
        $this->assertEquals(2400, (float) $order->total_amount);
        $this->assertEquals(600, (float) $order->discount_amount);
        $this->assertEquals(3000, (float) $order->subtotal); // pre-discount list subtotal
    }

    public function test_no_promotion_charges_full_price(): void
    {
        $product = $this->makeProduct(['KES' => 1500]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, 2))->assertStatus(201);
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();

        $this->assertEquals(3000, (float) $order->total_amount);
        $this->assertEquals(0, (float) $order->discount_amount);
    }

    public function test_product_api_overlays_discounted_sale_price_for_display(): void
    {
        $product = $this->makeProduct(['KES' => 1000]);
        $this->activePromotion(['discount_value' => 25]);

        $data = $this->getJson('/api/v1/products?per_page=50')->assertOk()->json('data');
        $row  = collect($data)->firstWhere('slug', $product->slug);
        $this->assertNotNull($row, 'product should appear in the public list');

        $kes = collect($row['prices'])->firstWhere('currency_code', 'KES');
        $this->assertEquals(1000, (float) $kes['regular_price']); // struck original intact
        $this->assertEquals(750, (float) $kes['sale_price']);      // 25% off overlaid
    }

    public function test_displayed_sale_price_equals_the_charged_price(): void
    {
        $product = $this->makeProduct(['KES' => 2000]);
        $this->activePromotion(['discount_value' => 15]);

        // What the shelf shows (per unit):
        $data  = $this->getJson('/api/v1/products?per_page=50')->json('data');
        $row   = collect($data)->firstWhere('slug', $product->slug);
        $shown = (float) collect($row['prices'])->firstWhere('currency_code', 'KES')['sale_price'];

        // What checkout charges (1 unit, no tax configured in tests):
        $res   = $this->postJson('/api/v1/storefront/orders', $this->payload($product, 1))->assertStatus(201);
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();

        $this->assertEquals(1700, $shown);                          // 2000 − 15%
        $this->assertEquals($shown, (float) $order->total_amount);  // display == charge
    }

    public function test_expired_promotion_does_not_apply(): void
    {
        $product = $this->makeProduct(['KES' => 1500]);
        $this->activePromotion(['starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, 1))->assertStatus(201);
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();

        $this->assertEquals(1500, (float) $order->total_amount); // no discount
    }
}
