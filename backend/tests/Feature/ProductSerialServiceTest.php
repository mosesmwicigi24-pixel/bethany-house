<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSerial;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-item serials across the production lifecycle: minted on approval, moved
 * into stock on completion (reconciled to the produced quantity), voided on
 * cancellation.
 */
class ProductSerialServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(int $qty): ProductionOrder
    {
        $product = Product::factory()->create();
        $outlet  = Outlet::factory()->create();

        return ProductionOrder::create([
            'product_id'        => $product->id,
            'outlet_id'         => $outlet->id,
            'quantity'          => $qty,
            'status'            => 'pending',
            'priority'          => 'normal',
            'is_customer_order' => false,
        ]);
    }

    public function test_approval_mints_one_serial_per_unit(): void
    {
        $order = $this->makeOrder(3);

        $this->assertSame(3, ProductSerialService::generateForProductionOrder($order));
        $this->assertSame(3, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_PRODUCTION)->count());
        $this->assertDatabaseHas('product_serials', ['serial_number' => $order->order_number . '-001']);

        // Idempotent — re-running assigns nothing more.
        $this->assertSame(0, ProductSerialService::generateForProductionOrder($order));
    }

    public function test_completion_moves_serials_into_stock_reconciled_down(): void
    {
        $order = $this->makeOrder(3);
        ProductSerialService::generateForProductionOrder($order);

        // Only 2 of the 3 ordered units were actually produced.
        ProductSerialService::stockFromProductionOrder($order, 99, $order->outlet_id, 2);

        $this->assertSame(2, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_STOCK)->count());
        $this->assertSame(1, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::CANCELLED)->count());
        $this->assertDatabaseHas('product_serials', [
            'production_order_id' => $order->id,
            'status'              => ProductSerial::IN_STOCK,
            'inventory_item_id'   => 99,
        ]);
    }

    public function test_completion_mints_extras_when_more_produced(): void
    {
        $order = $this->makeOrder(2);
        ProductSerialService::generateForProductionOrder($order);

        ProductSerialService::stockFromProductionOrder($order, 5, $order->outlet_id, 3);

        $this->assertSame(3, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_STOCK)->count());
    }

    public function test_cancellation_voids_in_production_serials(): void
    {
        $order = $this->makeOrder(2);
        ProductSerialService::generateForProductionOrder($order);

        ProductSerialService::cancelForProductionOrder($order);

        $this->assertSame(2, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::CANCELLED)->count());
    }
}
