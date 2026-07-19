<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ProductTranslation;
use App\Mail\StorefrontOrderReceiptMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_incomplete_measurements_are_rejected(): void
    {
        $gown = $this->makeProduct([
            'is_producible' => true,
            'measurements'  => [
                ['name' => 'Chest', 'unit' => 'in', 'required' => true],
                ['name' => 'Waist', 'unit' => 'in', 'required' => false],
            ],
        ]);

        // Customer chose made-to-measure (sent measurements) but skipped a
        // required field — refuse, don't guess.
        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($gown, [
            'items' => [['slug' => $gown->slug, 'quantity' => 1, 'measurements' => ['Waist' => '32']]],
        ]));

        $res->assertStatus(422);
        $this->assertStringContainsString('Chest', $res->json('message'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_producible_with_size_is_a_ready_made_stocked_line(): void
    {
        $gown = $this->makeProduct([
            'is_producible' => true,
            'measurements'  => [
                ['name' => 'Chest', 'unit' => 'in', 'required' => true],
            ],
        ], ['KES' => 12500]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($gown, [
            'items' => [['slug' => $gown->slug, 'quantity' => 1, 'size' => 'L']],
        ]));

        $res->assertStatus(201);

        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        $item  = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertFalse((bool) $item->requires_production);
        $this->assertNull($item->production_order_id);
        $this->assertStringContainsString('Size L', (string) $item->notes);
        $this->assertSame(0, ProductionOrder::count());
        $this->assertStringContainsString('READY-MADE', (string) $order->customer_notes);
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

    public function test_receipt_email_sent_when_email_provided(): void
    {
        Mail::fake();
        $product = $this->makeProduct();

        $this->postJson('/api/v1/storefront/orders', $this->payload($product, [
            'customer' => ['email' => 'jane@example.com'],
        ]))->assertStatus(201);

        Mail::assertSent(StorefrontOrderReceiptMail::class, fn ($m) => $m->hasTo('jane@example.com'));
    }

    public function test_no_receipt_email_without_address(): void
    {
        Mail::fake();
        $product = $this->makeProduct();

        $this->postJson('/api/v1/storefront/orders', $this->payload($product))->assertStatus(201);

        Mail::assertNothingSent();
    }

    public function test_status_endpoint_returns_live_order_state(): void
    {
        $product = $this->makeProduct();
        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product));
        $token = $res->json('order.payment_token');
        $this->assertNotEmpty($token);

        $status = $this->getJson("/api/v1/storefront/orders/{$token}");
        $status->assertOk()
            ->assertJsonPath('order_number', $res->json('order.order_number'))
            ->assertJsonPath('payment_status', 'pending')
            ->assertJsonPath('shipment', null);
        $this->assertStringContainsString('/pay/', $status->json('payment_link'));

        // Staff ship it -> tracking link appears
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        \App\Models\OrderShipment::create([
            'order_id'        => $order->id,
            'shipment_number' => 'SHP-TEST-1',
            'status'          => 'in_transit',
            'carrier'         => 'Bethany Rider',
            'tracking_number' => 'BR-001',
            'tracking_token'  => 'testtoken123',
        ]);

        $status2 = $this->getJson("/api/v1/storefront/orders/{$token}");
        $status2->assertOk()->assertJsonPath('shipment.status', 'in_transit');
        $this->assertStringContainsString('/track/testtoken123', $status2->json('shipment.tracking_url'));

        $this->getJson('/api/v1/storefront/orders/not-a-real-token')->assertNotFound();
    }

    public function test_variant_order_sets_variant_and_price(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'published_at' => now()->subDay(), 'product_type' => 'variable']);
        ProductTranslation::create(['product_id' => $product->id, 'language_code' => 'en', 'name' => 'Princes Cassock']);
        ProductPrice::create(['product_id' => $product->id, 'currency_code' => 'KES', 'regular_price' => 10000]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => $product->sku . '-BLU', 'variant_name' => 'Blue', 'attributes' => ['Colour' => 'Blue'], 'is_active' => true,
        ]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'currency_code' => 'KES', 'regular_price' => 13000]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, [
            'items' => [['slug' => $product->slug, 'variant_id' => $variant->id, 'quantity' => 1]],
        ]));

        $res->assertStatus(201);
        $order = Order::where('order_number', $res->json('order.order_number'))->firstOrFail();
        $item  = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame('Blue', $item->variant_name);
        $this->assertEquals(13000, (float) $item->unit_price); // variant price, not the 10000 base
        $this->assertStringContainsString('Blue', (string) $item->product_name);
        $this->assertEquals(13000, (float) $order->total_amount);
    }

    public function test_unknown_variant_is_rejected(): void
    {
        $product = $this->makeProduct(['product_type' => 'variable'], ['KES' => 5000]);
        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($product, [
            'items' => [['slug' => $product->slug, 'variant_id' => 999999, 'quantity' => 1]],
        ]));
        $res->assertStatus(422);
    }

    public function test_unpublished_products_are_refused(): void
    {
        $draft = $this->makeProduct(['status' => 'draft', 'published_at' => null]);

        $res = $this->postJson('/api/v1/storefront/orders', $this->payload($draft));

        $res->assertStatus(422);
        $this->assertSame(0, Order::count());
    }
}
