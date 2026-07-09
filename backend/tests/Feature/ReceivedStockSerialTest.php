<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods bought in for resale (no production run) must still get a unique serial
 * per unit at receipt — so they can be sold, dispatched, and reconciled for loss
 * exactly like manufactured units.
 */
class ReceivedStockSerialTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiving_mints_one_in_stock_serial_per_unit(): void
    {
        $product = Product::factory()->create();

        $created = ProductSerialService::receiveIntoStock(
            productId: $product->id,
            variantId: null,
            outletId: 1,
            inventoryItemId: null,
            qty: 3,
            sourceReference: 'purchase_order:77',
        );

        $this->assertSame(3, $created);
        $serials = ProductSerial::where('product_id', $product->id)->get();
        $this->assertCount(3, $serials);
        $this->assertTrue($serials->every(fn ($s) => $s->status === ProductSerial::IN_STOCK));
        $this->assertTrue($serials->every(fn ($s) => $s->production_order_id === null));
        $this->assertTrue($serials->every(fn ($s) => $s->source_reference === 'purchase_order:77'));
        $this->assertTrue($serials->every(fn ($s) => str_starts_with($s->serial_number, 'RCV-')));
        $this->assertTrue($serials->every(fn ($s) => $s->stocked_at !== null));
    }

    public function test_receiving_the_same_reference_again_only_tops_up(): void
    {
        $product = Product::factory()->create();

        ProductSerialService::receiveIntoStock($product->id, null, 1, null, 3, 'grn:5');
        // A retry/replay of the same receipt must not duplicate.
        $again = ProductSerialService::receiveIntoStock($product->id, null, 1, null, 3, 'grn:5');

        $this->assertSame(0, $again);
        $this->assertSame(3, ProductSerial::where('product_id', $product->id)->count());

        // Receiving MORE under the same reference tops up to the new quantity.
        $topUp = ProductSerialService::receiveIntoStock($product->id, null, 1, null, 5, 'grn:5');
        $this->assertSame(2, $topUp);
        $this->assertSame(5, ProductSerial::where('product_id', $product->id)->count());
    }

    public function test_serials_are_unique_across_products_under_one_reference(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        ProductSerialService::receiveIntoStock($a->id, null, 1, null, 2, 'purchase_order:9');
        ProductSerialService::receiveIntoStock($b->id, null, 1, null, 2, 'purchase_order:9');

        // No serial-number collision despite the shared reference.
        $numbers = ProductSerial::pluck('serial_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count());
        $this->assertSame(4, $numbers->count());
    }

    public function test_zero_or_blank_reference_is_a_no_op(): void
    {
        $product = Product::factory()->create();

        $this->assertSame(0, ProductSerialService::receiveIntoStock($product->id, null, 1, null, 0, 'x'));
        $this->assertSame(0, ProductSerialService::receiveIntoStock($product->id, null, 1, null, 3, ''));
        $this->assertSame(0, ProductSerial::where('product_id', $product->id)->count());
    }
}
