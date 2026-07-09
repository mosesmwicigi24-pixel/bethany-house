<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\PosInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The reservation model: a pending sale RESERVES stock (physical count untouched),
 * payment COMMITS it (physical count drops), and void/cancel returns it — a
 * reservation release if never paid, or a physical restore if it was.
 */
class PosInventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private InventoryItem $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::factory()->create();
        $this->inv = InventoryItem::create([
            'product_id'        => Product::factory()->create()->id,
            'product_variant_id' => null,
            'outlet_id'         => $this->outlet->id,
            'quantity_on_hand'  => 10,
            'quantity_reserved' => 0,
            'reorder_point'     => 0,
        ]);
    }

    private function orderWith(int $qty): Order
    {
        $order = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $this->outlet->id]);
        $order->setRelation('items', new Collection([
            new OrderItem([
                'product_id'         => $this->inv->product_id,
                'product_variant_id' => null,
                'quantity'           => $qty,
                'notes'              => null,
            ]),
        ]));
        return $order;
    }

    private function assertStock(int $onHand, int $reserved): void
    {
        $this->inv->refresh();
        $this->assertSame($onHand, (int) $this->inv->quantity_on_hand, 'quantity_on_hand');
        $this->assertSame($reserved, (int) $this->inv->quantity_reserved, 'quantity_reserved');
    }

    public function test_reserving_holds_stock_without_touching_the_physical_count(): void
    {
        $order = $this->orderWith(3);
        PosInventoryService::reserveForOrder($order);

        $this->assertStock(onHand: 10, reserved: 3);        // available now 7
        $this->assertNotNull($order->fresh()->stock_reserved_at);
    }

    public function test_paying_commits_the_deduction(): void
    {
        $order = $this->orderWith(3);
        PosInventoryService::reserveForOrder($order);
        PosInventoryService::commitForOrder($order);

        $this->assertStock(onHand: 7, reserved: 0);
        $this->assertNotNull($order->fresh()->stock_committed_at);

        // Idempotent — a second commit does not deduct again.
        PosInventoryService::commitForOrder($order);
        $this->assertStock(onHand: 7, reserved: 0);
    }

    public function test_voiding_an_unpaid_order_just_releases_the_reservation(): void
    {
        $order = $this->orderWith(3);
        PosInventoryService::reserveForOrder($order);
        PosInventoryService::unwindForOrder($order);

        // Physical count never moved; reservation released.
        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertNotNull($order->fresh()->stock_unwound_at);
    }

    public function test_voiding_a_paid_order_restores_the_physical_count(): void
    {
        $order = $this->orderWith(3);
        PosInventoryService::reserveForOrder($order);
        PosInventoryService::commitForOrder($order);
        $this->assertStock(onHand: 7, reserved: 0);

        PosInventoryService::unwindForOrder($order);
        $this->assertStock(onHand: 10, reserved: 0);
    }

    public function test_commit_skips_a_legacy_order_already_marked_committed(): void
    {
        // An order created under the old model: on_hand was already deducted and
        // it's flagged committed — commit must not deduct it a second time.
        $order = $this->orderWith(3);
        $order->forceFill(['stock_committed_at' => now()])->save();

        PosInventoryService::commitForOrder($order);

        $this->assertStock(onHand: 10, reserved: 0);   // untouched
    }

    public function test_unwind_is_idempotent(): void
    {
        $order = $this->orderWith(3);
        PosInventoryService::reserveForOrder($order);
        PosInventoryService::unwindForOrder($order);
        PosInventoryService::unwindForOrder($order);   // already unwound → no-op

        $this->assertStock(onHand: 10, reserved: 0);
    }
}
