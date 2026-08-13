<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/checkout — what delivery actually costs.
 *
 * checkout() priced shipping off `$shippingMethod->base_rate`, a column that
 * exists nowhere in the schema: shipping_methods holds `cost_type` +
 * `flat_rate`. The read was always null, so shipping_amount was always 0 and
 * every online delivery went out free no matter which method the customer
 * chose or what the storefront had quoted them.
 *
 * The rate now has one definition — ShippingMethod::calculateCost — which
 * POST /shipping/calculate quotes from and checkout() charges from, so the two
 * cannot drift. These tests price each cost_type, and pin quote == charge.
 */
class CheckoutShippingCostTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = 1500;

    private function stockedVariant(): ProductVariant
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
            'regular_price'      => self::UNIT_PRICE,
        ]);

        InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'outlet_id'          => null,
            'quantity_on_hand'   => 10,
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);

        return $variant;
    }

    /** See OnlineOrderStockFlagsTest::seedCart — carts and cart_items are split tables. */
    private function seedCart(User $user, ProductVariant $variant, int $qty): void
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

    private function zoneServingKenya(): int
    {
        DB::table('countries')->insertOrIgnore([
            'code' => 'KE', 'name' => 'Kenya', 'is_active' => true, 'is_shipping_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zoneId = DB::table('shipping_zones')->insertGetId([
            'name' => 'Kenya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('shipping_zone_countries')->insert([
            'shipping_zone_id' => $zoneId,
            'country_code'     => 'KE',
        ]);

        return $zoneId;
    }

    private function method(string $costType, ?float $rate, ?float $minOrder = null, ?int $zoneId = null): ShippingMethod
    {
        return ShippingMethod::create([
            'shipping_zone_id' => $zoneId ?? $this->zoneServingKenya(),
            'name'             => "Courier ({$costType})",
            'cost_type'        => $costType,
            'flat_rate'        => $rate,
            'min_order_amount' => $minOrder,
            'is_active'        => true,
            'sort_order'       => 0,
        ]);
    }

    /** Sign in a customer with a cart of $qty and a saved Kenyan address. */
    private function customerWithCart(int $qty = 2): array
    {
        $variant = $this->stockedVariant();
        $user    = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedCart($user, $variant, $qty);

        $address = Address::create([
            'user_id'       => $user->id,
            'address_type'  => 'shipping',
            'first_name'    => 'Jane',
            'last_name'     => 'Mwangi',
            'address_line1' => 'Moi Avenue 42',
            'city'          => 'Nairobi',
            'country_code'  => 'KE',
        ]);

        return [$user, $address];
    }

    private function checkout(Address $address, ShippingMethod $method)
    {
        return $this->postJson('/api/v1/checkout', [
            'delivery_method'     => 'delivery',
            'shipping_address_id' => $address->id,
            'shipping_method_id'  => $method->id,
            'payment_method'      => 'cash_on_delivery',
        ]);
    }

    public function test_a_flat_rate_method_is_charged_at_its_rate(): void
    {
        [$user, $address] = $this->customerWithCart(qty: 2);
        $method = $this->method('flat_rate', 350);

        $order = Order::findOrFail($this->checkout($address, $method)->assertStatus(201)->json('order.id'));

        $this->assertEquals(350, (float) $order->shipping_amount);
        // …and the customer is billed for it, rather than it vanishing.
        $this->assertEquals(
            (float) $order->subtotal + (float) $order->tax_amount + 350,
            (float) $order->total_amount,
        );
    }

    public function test_a_free_method_costs_nothing(): void
    {
        [$user, $address] = $this->customerWithCart(qty: 2);
        $method = $this->method('free', 999);

        $order = Order::findOrFail($this->checkout($address, $method)->assertStatus(201)->json('order.id'));

        $this->assertEquals(0, (float) $order->shipping_amount);
    }

    public function test_a_percentage_method_is_charged_against_the_goods_total(): void
    {
        // 10% of 2 × KES 1,500. This is the one that silently returned 0 in
        // ShippingMethod::calculateCost even before checkout dropped it.
        [$user, $address] = $this->customerWithCart(qty: 2);
        $method = $this->method('percentage', 10);

        $order = Order::findOrFail($this->checkout($address, $method)->assertStatus(201)->json('order.id'));

        $goods = (float) $order->subtotal + (float) $order->tax_amount;
        $this->assertEquals(round($goods * 0.10, 2), (float) $order->shipping_amount);
        $this->assertGreaterThan(0, (float) $order->shipping_amount);
    }

    public function test_a_method_below_its_minimum_order_is_refused_not_billed(): void
    {
        // The cart is KES 1,500; the method is only offered from KES 5,000 up,
        // so /shipping/calculate would never have listed it.
        [$user, $address] = $this->customerWithCart(qty: 1);
        $method = $this->method('flat_rate', 350, minOrder: 5000);

        $this->checkout($address, $method)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That delivery option is not available for this order.');

        $this->assertSame(0, Order::count());
        $this->assertSame(10, (int) InventoryItem::firstOrFail()->quantity_on_hand);
    }

    public function test_an_inactive_method_is_refused(): void
    {
        [$user, $address] = $this->customerWithCart(qty: 2);
        $method = $this->method('flat_rate', 350);
        $method->update(['is_active' => false]);

        $this->checkout($address, $method)->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_the_quote_and_the_charge_agree(): void
    {
        // The property that matters: whatever POST /shipping/calculate showed the
        // customer is what checkout() bills them, for every cost type.
        // One zone for all of them — /shipping/calculate resolves a country to a
        // single zone, so a second zone serving KE would never be reached.
        $zoneId = $this->zoneServingKenya();

        foreach ([['flat_rate', 350.0], ['free', 999.0], ['percentage', 10.0]] as [$costType, $rate]) {
            [$user, $address] = $this->customerWithCart(qty: 2);
            $method = $this->method($costType, $rate, zoneId: $zoneId);

            $goods = 2 * self::UNIT_PRICE;

            $quoted = collect($this->postJson('/api/v1/shipping/calculate', [
                'country'    => 'KE',
                'cart_total' => $goods,
            ])->assertStatus(200)->json('available_methods'))
                ->firstWhere('id', $method->id);

            $this->assertNotNull($quoted, "{$costType} should be quoted");

            $order = Order::findOrFail($this->checkout($address, $method)->assertStatus(201)->json('order.id'));

            $this->assertEquals(
                (float) $quoted['cost'],
                (float) $order->shipping_amount,
                "quoted and charged shipping must match for {$costType}",
            );
        }
    }
}
