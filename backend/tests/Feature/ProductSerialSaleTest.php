<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase 2: selling a serialized item flips the specific in-stock serials to sold
 * (removing them from the shelf), edits reconcile, and voiding returns them.
 */
class ProductSerialSaleTest extends TestCase
{
    use RefreshDatabase;

    private function stockSerials(int $productId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ProductSerial::create([
                'serial_number' => "SN-{$productId}-{$i}",
                'product_id'    => $productId,
                'status'        => ProductSerial::IN_STOCK,
            ]);
        }
    }

    /** Build a real order with in-memory items (product_id, quantity, notes). */
    private function orderWith(array $items): Order
    {
        $outlet = Outlet::factory()->create();
        $order  = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id]);

        $models = new Collection(array_map(
            fn ($i) => new OrderItem([
                'product_id' => $i['product_id'],
                'quantity'   => $i['quantity'],
                'notes'      => $i['notes'] ?? null,
            ]),
            $items,
        ));
        $order->setRelation('items', $models);

        return $order;
    }

    public function test_selling_marks_the_specific_units_sold(): void
    {
        $product = Product::factory()->create();
        $this->stockSerials($product->id, 3);

        $order = $this->orderWith([['product_id' => $product->id, 'quantity' => 2]]);
        ProductSerialService::syncSoldForOrder($order);

        $this->assertSame(2, ProductSerial::where('order_id', $order->id)->where('status', ProductSerial::SOLD)->count());
        $this->assertSame(1, ProductSerial::where('product_id', $product->id)->where('status', ProductSerial::IN_STOCK)->count());
    }

    public function test_editing_the_cart_down_releases_surplus_units(): void
    {
        $product = Product::factory()->create();
        $this->stockSerials($product->id, 3);

        $order = $this->orderWith([['product_id' => $product->id, 'quantity' => 2]]);
        ProductSerialService::syncSoldForOrder($order);

        // Cart reduced to 1 unit → one sold serial goes back to the shelf.
        $order->setRelation('items', new Collection([
            new OrderItem(['product_id' => $product->id, 'quantity' => 1, 'notes' => null]),
        ]));
        ProductSerialService::syncSoldForOrder($order);

        $this->assertSame(1, ProductSerial::where('order_id', $order->id)->where('status', ProductSerial::SOLD)->count());
        $this->assertSame(2, ProductSerial::where('product_id', $product->id)->where('status', ProductSerial::IN_STOCK)->count());
    }

    public function test_voiding_returns_units_to_the_shelf(): void
    {
        $product = Product::factory()->create();
        $this->stockSerials($product->id, 2);

        $order = $this->orderWith([['product_id' => $product->id, 'quantity' => 2]]);
        ProductSerialService::syncSoldForOrder($order);
        $this->assertSame(2, ProductSerial::where('status', ProductSerial::SOLD)->count());

        ProductSerialService::releaseForOrder($order);

        $this->assertSame(0, ProductSerial::where('status', ProductSerial::SOLD)->count());
        $this->assertSame(2, ProductSerial::where('product_id', $product->id)->where('status', ProductSerial::IN_STOCK)->count());
    }

    public function test_made_to_order_lines_are_skipped(): void
    {
        $product = Product::factory()->create();
        $this->stockSerials($product->id, 2);

        $order = $this->orderWith([[
            'product_id' => $product->id,
            'quantity'   => 2,
            'notes'      => '__MTO__|custom measurements',
        ]]);
        ProductSerialService::syncSoldForOrder($order);

        $this->assertSame(0, ProductSerial::where('status', ProductSerial::SOLD)->count());
        $this->assertSame(2, ProductSerial::where('status', ProductSerial::IN_STOCK)->count());
    }
}
