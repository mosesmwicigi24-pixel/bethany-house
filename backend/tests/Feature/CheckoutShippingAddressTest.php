<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/checkout — where a delivery order is actually going.
 *
 * checkout() validated shipping_address_id and then discarded it. Orders carry
 * no shipping_address_id column, so a delivery order was created with no
 * destination recorded anywhere: not the street, not the city, not the country.
 *
 * The rule was also `exists:addresses,id`, which asks whether a row exists and
 * not whose it is — any authenticated customer could name any other customer's
 * address id.
 *
 * The address is now snapshotted onto the order's own shipping_* columns, the
 * way StorefrontCheckoutController already does it, so editing or deleting the
 * saved address afterwards cannot rewrite where a placed order was sent.
 */
class CheckoutShippingAddressTest extends TestCase
{
    use RefreshDatabase;

    private function stockedVariant(int $onHand = 10): ProductVariant
    {
        $product = Product::factory()->create([
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ]);
        ProductTranslation::create([
            'product_id'    => $product->id,
            'language_code' => 'en',
            'name'          => 'Chasuble',
        ]);

        $variant = ProductVariant::create([
            'product_id'   => $product->id,
            'sku'          => 'SKU-' . $product->id,
            'variant_name' => 'Medium',
            'attributes'   => ['size' => 'M'],
            'is_active'    => true,
        ]);
        ProductPrice::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'currency_code'      => 'KES',
            'regular_price'      => 1500,
        ]);

        InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'outlet_id'          => null,
            'quantity_on_hand'   => $onHand,
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);

        return $variant;
    }

    /** See OnlineOrderStockFlagsTest::seedCart — carts and cart_items are split tables. */
    private function seedCart(User $user, ProductVariant $variant, int $qty = 2): void
    {
        $cartId = DB::table('carts')->insertGetId([
            'user_id'       => $user->id,
            'cart_type'     => 'shopping',
            'currency_code' => 'KES',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('shopping_carts')->insert([
            'id'            => $cartId,
            'user_id'       => $user->id,
            'currency_code' => 'KES',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('cart_items')->insert([
            'cart_id'            => $cartId,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity'           => $qty,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function addressFor(array $owner, array $overrides = []): Address
    {
        return Address::create(array_merge([
            'address_type'   => 'shipping',
            'label'          => 'Home',
            'first_name'     => 'Jane',
            'last_name'      => 'Mwangi',
            'address_line1'  => 'Moi Avenue 42',
            'address_line2'  => 'Apartment 7B',
            'city'           => 'Nairobi',
            'state_province' => 'Nairobi County',
            'postal_code'    => '00100',
            'country_code'   => 'KE',
            'phone'          => '+254712345678',
        ], $owner, $overrides));
    }

    private function shippingMethod(string $name = 'Courier — next day'): int
    {
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'name'       => 'Nairobi',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('shipping_methods')->insertGetId([
            'shipping_zone_id' => $zoneId,
            'name'             => $name,
            'cost_type'        => 'flat',
            'flat_rate'        => 350,
            'is_active'        => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_a_delivery_order_records_where_it_is_going(): void
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant);

        $address = $this->addressFor(['user_id' => $user->id]);

        $res = $this->postJson('/api/v1/checkout', [
            'delivery_method'     => 'delivery',
            'shipping_address_id' => $address->id,
            'shipping_method_id'  => $this->shippingMethod(),
            'payment_method'      => 'cash_on_delivery',
        ]);

        $res->assertStatus(201);
        $order = Order::findOrFail($res->json('order.id'));

        $this->assertSame('Moi Avenue 42', $order->shipping_address_line1);
        $this->assertSame('Apartment 7B', $order->shipping_address_line2);
        $this->assertSame('Nairobi', $order->shipping_city);
        $this->assertSame('Nairobi County', $order->shipping_state);
        $this->assertSame('00100', $order->shipping_postal_code);
        $this->assertSame('KE', $order->shipping_country_code);
        $this->assertSame('Courier — next day', $order->shipping_method);
    }

    public function test_the_destination_is_a_snapshot_and_does_not_follow_the_saved_address(): void
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant);

        $address = $this->addressFor(['user_id' => $user->id]);

        $order = Order::findOrFail($this->postJson('/api/v1/checkout', [
            'delivery_method'     => 'delivery',
            'shipping_address_id' => $address->id,
            'shipping_method_id'  => $this->shippingMethod(),
            'payment_method'      => 'cash_on_delivery',
        ])->assertStatus(201)->json('order.id'));

        // The customer moves house after ordering.
        $address->update(['address_line1' => 'Ngong Road 900', 'city' => 'Kisumu']);

        $order->refresh();
        $this->assertSame('Moi Avenue 42', $order->shipping_address_line1);
        $this->assertSame('Nairobi', $order->shipping_city);
    }

    public function test_an_address_owned_through_the_customer_profile_is_accepted(): void
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant);

        // How the customer portal actually saves them: on the customer record,
        // with no user_id of their own (CustomerController::storeAddress).
        $customer = Customer::create([
            'first_name' => 'Jane',
            'last_name'  => 'Mwangi',
            'phone'      => '+254712345678',
            'user_id'    => $user->id,
        ]);
        $address = $this->addressFor(['customer_id' => $customer->id]);

        $this->postJson('/api/v1/checkout', [
            'delivery_method'     => 'delivery',
            'shipping_address_id' => $address->id,
            'shipping_method_id'  => $this->shippingMethod(),
            'payment_method'      => 'cash_on_delivery',
        ])->assertStatus(201);
    }

    public function test_another_customers_address_is_refused_and_no_order_is_created(): void
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant);

        $stranger        = User::factory()->create();
        $strangersAddress = $this->addressFor(['user_id' => $stranger->id]);

        $this->postJson('/api/v1/checkout', [
            'delivery_method'     => 'delivery',
            'shipping_address_id' => $strangersAddress->id,
            'shipping_method_id'  => $this->shippingMethod(),
            'payment_method'      => 'cash_on_delivery',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Shipping address not found');

        $this->assertSame(0, Order::count());
        // Refused before any stock moved, too.
        $this->assertSame(10, (int) InventoryItem::firstOrFail()->quantity_on_hand);
    }

    public function test_a_pickup_order_needs_no_address_and_records_none(): void
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant);

        $order = Order::findOrFail($this->postJson('/api/v1/checkout', [
            'delivery_method'    => 'pickup',
            'pickup_location_id' => Outlet::factory()->create()->id,
            'payment_method'     => 'cash_on_delivery',
        ])->assertStatus(201)->json('order.id'));

        $this->assertNull($order->shipping_address_line1);
        $this->assertNull($order->shipping_country_code);
        $this->assertNull($order->shipping_method);
        $this->assertNotNull($order->pickup_outlet_id);
    }
}
