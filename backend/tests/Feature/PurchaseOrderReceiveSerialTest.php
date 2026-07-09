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
        // procurement.receive only (no role) so the goods-received notification
        // resolves to nobody and the RefreshDatabase transaction stays clean.
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
}
