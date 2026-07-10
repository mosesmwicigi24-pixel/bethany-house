<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Receiving purchased (non-manufactured) goods against a PO must mint one unique
 * serial per accepted unit, straight into stock — so bought-in goods are tracked,
 * dispatched, and reconciled for loss exactly like produced units.
 */
class PurchaseOrderReceiveSerialTest extends TestCase
{
    use RefreshDatabase;

    private function actAsReceiver(): void
    {
        $user = User::factory()->create();
        // The purchase-orders route group requires procurement.view; the receive
        // endpoint itself requires procurement.receive. Grant both directly (no
        // role) so the goods-received notification resolves to nobody and the
        // RefreshDatabase transaction stays clean.
        $user->givePermissionTo(Permission::findOrCreate('procurement.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('procurement.receive', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function approvedPoWithProduct(int $qty, int $outletId, int $productId): PurchaseOrder
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-1', 'name' => 'Acme Wholesale',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $po = PurchaseOrder::create([
            'po_number'    => 'PO-TEST-1',
            'supplier_id'  => $supplierId,
            'outlet_id'    => $outletId,
            'order_date'   => now()->toDateString(),
            'status'       => 'approved',
            'currency_code'=> 'KES',
            'subtotal'     => 1000,
            'total_amount' => 1000,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_type'         => 'product',
            'product_id'        => $productId,
            'description'       => 'Bought-in item',
            'quantity'          => $qty,
            'quantity_received' => 0,
            'unit_price'        => 200,
            'total_price'       => 200 * $qty,
        ]);

        return $po->load('items');
    }

    public function test_receiving_a_po_mints_a_serial_per_unit_into_stock(): void
    {
        Notification::fake();
        $this->actAsReceiver();

        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();
        $po      = $this->approvedPoWithProduct(5, $outlet->id, $product->id);
        $poItem  = $po->items->first();

        $res = $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $outlet->id,
            'items'         => [
                ['po_item_id' => $poItem->id, 'quantity_received' => 5],
            ],
        ]);

        $res->assertOk();

        // Stock rose by 5…
        $inv = InventoryItem::where('product_id', $product->id)
            ->where('outlet_id', $outlet->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame(5, (int) $inv->quantity_on_hand);

        // …and 5 unique in-stock serials exist, tied to this receipt, no production.
        $serials = ProductSerial::where('product_id', $product->id)->get();
        $this->assertCount(5, $serials);
        $this->assertTrue($serials->every(fn ($s) => $s->status === ProductSerial::IN_STOCK));
        $this->assertTrue($serials->every(fn ($s) => $s->production_order_id === null));
        $this->assertTrue($serials->every(fn ($s) => $s->outlet_id === $outlet->id));
        $this->assertTrue($serials->every(fn ($s) => str_starts_with($s->serial_number, 'RCV-')));
        $this->assertTrue($serials->every(fn ($s) => str_starts_with((string) $s->source_reference, 'grn:')));
        $this->assertSame(5, $serials->pluck('serial_number')->unique()->count());
    }

    public function test_a_second_partial_receipt_adds_more_serials(): void
    {
        Notification::fake();
        $this->actAsReceiver();

        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();
        $po      = $this->approvedPoWithProduct(5, $outlet->id, $product->id);
        $poItem  = $po->items->first();

        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'outlet', 'outlet_id' => $outlet->id,
            'items' => [['po_item_id' => $poItem->id, 'quantity_received' => 2]],
        ])->assertOk();

        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'outlet', 'outlet_id' => $outlet->id,
            'items' => [['po_item_id' => $poItem->id, 'quantity_received' => 3]],
        ])->assertOk();

        // Two GRNs → two distinct references → 2 + 3 = 5 serials, all unique.
        $serials = ProductSerial::where('product_id', $product->id)->get();
        $this->assertSame(5, $serials->count());
        $this->assertSame(5, $serials->pluck('serial_number')->unique()->count());
    }

    public function test_warehouse_receive_does_not_fan_out_into_outlet_rows(): void
    {
        Notification::fake();
        $this->actAsReceiver();

        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();

        // An outlet already carries its own stock for this product.
        $outletRow = InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $outlet->id, 'quantity_on_hand' => 5,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        $po     = $this->approvedPoWithProduct(10, $outlet->id, $product->id);
        $poItem = $po->items->first();

        // Receive 10 to the WAREHOUSE (no outlet).
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'warehouse',
            'items'         => [['po_item_id' => $poItem->id, 'quantity_received' => 10]],
        ])->assertOk();

        // Warehouse row holds the 10; the outlet row is UNCHANGED (no fan-out).
        $warehouse = InventoryItem::where('product_id', $product->id)->whereNull('outlet_id')->first();
        $this->assertNotNull($warehouse);
        $this->assertSame(10, (int) $warehouse->quantity_on_hand);
        $this->assertSame(5, (int) $outletRow->fresh()->quantity_on_hand);

        // Total physical on hand across all rows = 10 received + 5 pre-existing = 15
        // (not 10 + 10 + 5 = 25 the fan-out used to produce).
        $this->assertSame(15, (int) InventoryItem::where('product_id', $product->id)->sum('quantity_on_hand'));

        // And exactly 10 serials, at the warehouse.
        $this->assertSame(10, ProductSerial::where('product_id', $product->id)->count());
    }
}
