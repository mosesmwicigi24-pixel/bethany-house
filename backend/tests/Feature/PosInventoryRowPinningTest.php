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
 * Regression guard: commit / unwind must act on the exact inventory row the sale
 * drew from. Two simple (no-variant) products in the same outlet both have
 * `product_variant_id IS NULL`; the old inventoryFor() matched on variant+outlet
 * only, so it could commit/unwind an ARBITRARY other product's stock. Pinning the
 * row on the line (inventory_item_id) makes it exact.
 */
class PosInventoryRowPinningTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private InventoryItem $invA;
    private InventoryItem $invB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::factory()->create();
        $this->invA = $this->stockRow(10);
        $this->invB = $this->stockRow(10);   // a second simple product, same outlet
    }

    private function stockRow(int $onHand): InventoryItem
    {
        return InventoryItem::create([
            'product_id'         => Product::factory()->create()->id,
            'product_variant_id' => null,
            'outlet_id'          => $this->outlet->id,
            'quantity_on_hand'   => $onHand,
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);
    }

    private function orderFor(InventoryItem $inv, int $qty, bool $pin): Order
    {
        $order = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $this->outlet->id]);
        $order->setRelation('items', new Collection([
            new OrderItem([
                'product_id'         => $inv->product_id,
                'product_variant_id' => null,
                'inventory_item_id'  => $pin ? $inv->id : null,
                'quantity'           => $qty,
                'notes'              => null,
            ]),
        ]));
        return $order;
    }

    private function assertRow(InventoryItem $inv, int $onHand, int $reserved): void
    {
        $inv->refresh();
        $this->assertSame($onHand, (int) $inv->quantity_on_hand, "on_hand for #{$inv->id}");
        $this->assertSame($reserved, (int) $inv->quantity_reserved, "reserved for #{$inv->id}");
    }

    public function test_commit_deducts_the_pinned_row_and_leaves_the_other_product_untouched(): void
    {
        $order = $this->orderFor($this->invA, 3, pin: true);

        PosInventoryService::reserveForOrder($order);
        $this->assertRow($this->invA, 10, 3);
        $this->assertRow($this->invB, 10, 0);   // the other product must not move

        PosInventoryService::commitForOrder($order);
        $this->assertRow($this->invA, 7, 0);
        $this->assertRow($this->invB, 10, 0);
    }

    public function test_unwind_of_a_committed_order_restores_only_the_pinned_row(): void
    {
        $order = $this->orderFor($this->invA, 4, pin: true);

        PosInventoryService::reserveForOrder($order);
        PosInventoryService::commitForOrder($order);
        PosInventoryService::unwindForOrder($order);

        $this->assertRow($this->invA, 10, 0);
        $this->assertRow($this->invB, 10, 0);
    }

    public function test_legacy_line_without_a_pin_resolves_by_product_id_not_variant(): void
    {
        // No inventory_item_id (pre-migration line). Must still resolve to product
        // A's row via product_id scoping — never the other null-variant product.
        $order = $this->orderFor($this->invA, 2, pin: false);

        PosInventoryService::reserveForOrder($order);
        PosInventoryService::commitForOrder($order);

        $this->assertRow($this->invA, 8, 0);
        $this->assertRow($this->invB, 10, 0);
    }
}
