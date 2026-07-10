<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serials must track the physical count through the whole lifecycle: a void of a
 * dispatched order returns them, a partial return restocks exactly that many, and
 * flagging a unit missing removes it from sellable stock.
 */
class ProductSerialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function serial(string $sn, array $attrs = []): ProductSerial
    {
        return ProductSerial::create(array_merge([
            'serial_number' => $sn,
            'product_id'    => $attrs['product_id'] ?? Product::factory()->create()->id,
            'status'        => ProductSerial::IN_STOCK,
        ], $attrs));
    }

    public function test_release_returns_dispatched_units_not_just_sold(): void
    {
        $order  = Order::factory()->create(['order_type' => 'pos']);
        $s = $this->serial('SN-DISP', [
            'order_id' => $order->id, 'status' => ProductSerial::DISPATCHED,
            'sold_at' => now(), 'dispatched_at' => now(),
        ]);

        ProductSerialService::releaseForOrder($order);

        $s->refresh();
        $this->assertSame(ProductSerial::IN_STOCK, $s->status);
        $this->assertNull($s->order_id);
        $this->assertNull($s->dispatched_at);
    }

    public function test_partial_return_restocks_exactly_that_many_serials(): void
    {
        $order   = Order::factory()->create(['order_type' => 'pos']);
        $product = Product::factory()->create();
        foreach (['A', 'B', 'C'] as $n) {
            $this->serial("SN-{$n}", [
                'product_id' => $product->id, 'order_id' => $order->id,
                'status' => ProductSerial::SOLD, 'sold_at' => now(),
            ]);
        }

        $returned = ProductSerialService::returnUnitsForOrder($order, $product->id, 2);

        $this->assertSame(2, $returned);
        $this->assertSame(2, ProductSerial::where('product_id', $product->id)
            ->where('status', ProductSerial::IN_STOCK)->count());
        $this->assertSame(1, ProductSerial::where('product_id', $product->id)
            ->where('status', ProductSerial::SOLD)->count());
    }

    public function test_flagging_missing_reduces_sellable_stock(): void
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();
        $inv = InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $outlet->id, 'quantity_on_hand' => 5,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        foreach (range(1, 5) as $i) {
            $this->serial("SN-{$i}", [
                'product_id' => $product->id, 'outlet_id' => $outlet->id,
                'inventory_item_id' => $inv->id, 'status' => ProductSerial::IN_STOCK,
            ]);
        }

        // Only 3 of the 5 are physically found on the shelf.
        $result = ProductSerialService::reconcile(
            $product->id, $outlet->id, ['SN-1', 'SN-2', 'SN-3'], flagMissing: true,
        );

        $this->assertCount(2, $result['missing']);
        // The two ghost units are removed from sellable stock.
        $this->assertSame(3, (int) $inv->fresh()->quantity_on_hand);
        $this->assertSame(2, ProductSerial::where('status', ProductSerial::MISSING)->count());
    }
}
