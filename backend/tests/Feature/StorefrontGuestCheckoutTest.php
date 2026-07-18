<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest checkout bridge (POST /api/v1/storefront/orders): the storefront's
 * one-shot order endpoint. Covers currency resolution (KE→KES, else USD),
 * made-to-order lines raising linked production orders with the customer's
 * measurements, required-measurement validation against the product
 * template, and client_request_id idempotency.
 */
class StorefrontGuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attrs = [], array $prices = ['KES' => 1500, 'USD' => 14]): Product
    {
        $product = Product::factory()->create(array_merge([
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ], $attrs));

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

    private function payload(Product $product, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => [
                'first_name' => 'Jane',
                'last_name'  => 'Mwangi',
                'phone'      => '+254712345678',
                'church'     => "St. Andrew's Cathedral",
            ],
            'delivery' => [
                'method'  => 'delivery',
                'address' => 'Moi Avenue, Nairobi',
                'city'    => 'Nairobi',
            ],
            'payment_method' => 'mpesa',
            'items'          => [
                ['slug' => $product->slug, 'quantity' => 2],
            ],
        ], $overrides);
    }

    public function test_kenyan_guest_order_is_created_as_online_in_kes(): void
    {
        $product = $this->makeProduct();

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, [
            'country_code' => 'KE',
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('order.currency_code', 'KES');

        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        $this->assertSame('online', $order->order_type);
        $this->assertSame('pending', $order->status);
        $this->assertSame('KE', $order->customer_country_code);
        $this->assertFalse((bool) $order->is_international);
        $this->assertEquals(3000, (float) $order->total_amount); // 2 × KES 1,500
        $this->assertNull($order->user_id);
        $this->assertStringContainsString('/pay/', $res->json('payment_link'));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_international_order_resolves_to_usd(): void
    {
        $product = $this->makeProduct();

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, [
            'country_code'   => 'US',
            'payment_method' => 'card',
        ]));

        $res->assertStatus(201)->assertJsonPath('order.currency_code', 'USD');

        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        $this->assertTrue((bool) $order->is_international);
        $this->assertEquals(28, (float) $order->total_amount); // 2 × $14
    }

    public function test_producible_line_raises_a_linked_production_order(): void
    {
        $gown = $this->makeProduct([
            'is_producible' => true,
            'measurements'  => [
                ['name' => 'Chest', 'unit' => 'in', 'required' => true],
                ['name' => 'Full Length', 'unit' => 'in', 'required' => true],
                ['name' => 'Waist', 'unit' => 'in', 'required' => false],
            ],
        ], ['KES' => 12500, 'USD' => 96]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($gown, [
            'items' => [[
                'slug'         => $gown->slug,
                'quantity'     => 1,
                'measurements' => ['Chest' => '42', 'Full Length' => '58'],
            ]],
        ]));

        $res->assertStatus(201);

        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        $item  = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertTrue((bool) $item->requires_production);
        $this->assertNotNull($item->production_order_id);

        $po = ProductionOrder::findOrFail($item->production_order_id);
        $this->assertSame($order->id, $po->customer_order_id);
        $this->assertSame($item->id, $po->order_item_id);
        $this->assertSame($gown->id, $po->product_id);
        $this->assertTrue((bool) $po->is_customer_order);
        $this->assertSame('42', $po->measurements['Chest']);
        $this->assertSame('58', $po->measurements['Full Length']);
        $this->assertStringStartsWith('PRD-', $po->order_number);

        // Measurements are also visible to staff on the order itself
        $this->assertStringContainsString('Chest: 42', (string) $order->customer_notes);
    }

    public function test_missing_required_measurements_are_rejected(): void
    {
        $gown = $this->makeProduct([
            'is_producible' => true,
            'measurements'  => [
                ['name' => 'Chest', 'unit' => 'in', 'required' => true],
            ],
        ]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($gown, [
            'items' => [['slug' => $gown->slug, 'quantity' => 1]],
        ]));

        $res->assertStatus(422);
        $this->assertStringContainsString('Chest', $res->json('message'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_same_client_request_id_is_idempotent(): void
    {
        $product = $this->makeProduct();
        $payload = $this->payload($product, ['client_request_id' => 'sf-abc-123']);

        $first  = $this->postJson('/api/v1/storefront/orders', $payload);
        $second = $this->postJson('/api/v1/storefront/orders', $payload);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame(
            $first->json('order.order_number'),
            $second->json('order.order_number'),
        );
        $this->assertSame(1, Order::count());
    }

    public function test_unpublished_products_are_refused(): void
    {
        $draft = $this->makeProduct(['status' => 'draft', 'published_at' => null]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($draft));

        $res->assertStatus(422);
        $this->assertSame(0, Order::count());
    }
}
