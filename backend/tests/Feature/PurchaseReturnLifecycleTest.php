<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A purchase return takes goods out of stock and lowers the PO line's
 * received count the moment it is RAISED. These tests pin the rest of its
 * life:
 *
 *  - Rejecting it gives back exactly what raising it took — stock (product
 *    and material) and the received count. It used to change only the
 *    status, so goods that never left the building vanished from stock.
 *  - Rejecting is once-only: a second reject (or an approve after it)
 *    cannot restore the stock twice.
 *  - The reversal follows the stock ledger, so a return that found no stock
 *    row to decrement restores nothing — stock is never minted from air.
 *  - Returning the same goods twice in a row is refused (already true,
 *    because raising a return lowers received; pinned here).
 */
class PurchaseReturnLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = User::factory()->create();
        foreach (['procurement.view', 'procurement.create', 'procurement.receive', 'procurement.approve'] as $p) {
            $this->user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->user);
    }

    private function line(string $type, int $itemId, float $qty): PurchaseOrderItem
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Mint Fabrics',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-RET-' . uniqid(), 'supplier_id' => $supplierId,
            'order_date' => now()->toDateString(), 'status' => 'approved',
            'currency_code' => 'KES', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_type'         => $type,
            $type === 'product' ? 'product_id' : 'material_id' => $itemId,
            'description'       => 'Line',
            'quantity'          => $qty,
            'quantity_received' => 0,
            'unit_price'        => 100,
            'total_price'       => 100 * $qty,
        ]);
    }

    private function receive(PurchaseOrderItem $line, float $qty, int $outletId): void
    {
        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $outletId,
            'items'         => [['po_item_id' => $line->id, 'quantity_received' => $qty]],
        ])->assertOk();
    }

    private function raiseReturn(PurchaseOrderItem $line, float $qty)
    {
        return $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/return", [
            'items' => [['po_item_id' => $line->id, 'quantity' => $qty, 'reason' => 'Flawed weave']],
        ]);
    }

    private function material(): Material
    {
        return Material::create([
            'code' => 'MAT-' . uniqid(), 'name' => 'Royal blue mint material',
            'category' => 'fabric', 'unit_of_measure' => 'm',
            'unit_cost' => 140, 'is_active' => true,
        ]);
    }

    private function materialOnHand(Material $m): float
    {
        return (float) MaterialInventory::where('material_id', $m->id)->value('quantity_on_hand');
    }

    public function test_rejecting_a_material_return_restores_stock_and_received_exactly_once(): void
    {
        $mat    = $this->material();
        $outlet = Outlet::factory()->create();
        $line   = $this->line('material', $mat->id, 2.00);
        $this->receive($line, 2.00, $outlet->id);

        $this->raiseReturn($line, 0.75)->assertSuccessful();
        $this->assertEquals(1.25, $this->materialOnHand($mat));
        $this->assertSame('1.25', (string) $line->fresh()->quantity_received);

        $return = PurchaseReturn::firstOrFail();
        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/reject", ['reason' => 'Supplier accepted the cloth after all'])
            ->assertOk();

        // Back exactly where it was before the return was raised.
        $this->assertEquals(2.00, $this->materialOnHand($mat));
        $this->assertSame('2.00', (string) $line->fresh()->quantity_received);
        $this->assertSame('rejected', $return->fresh()->status);

        // Once only: neither a second reject nor an approve can touch it again.
        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/reject", ['reason' => 'again'])
            ->assertStatus(404);
        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/approve")
            ->assertStatus(404);
        $this->assertEquals(2.00, $this->materialOnHand($mat), 'Stock was restored twice.');
    }

    public function test_rejecting_a_product_return_restores_the_same_stock_row(): void
    {
        $product = Product::factory()->create();
        $outlet  = Outlet::factory()->create();
        $line    = $this->line('product', $product->id, 5);
        $this->receive($line, 5, $outlet->id);

        $onHand = fn () => (int) InventoryItem::where('product_id', $product->id)->sum('quantity_on_hand');
        $this->assertSame(5, $onHand());

        $this->raiseReturn($line, 2)->assertSuccessful();
        $this->assertSame(3, $onHand());

        $return = PurchaseReturn::firstOrFail();
        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/reject", ['reason' => 'Not faulty'])
            ->assertOk();

        $this->assertSame(5, $onHand());
        $this->assertSame('5.00', (string) $line->fresh()->quantity_received);

        // The ledger shows both movements, so the round trip is auditable.
        $types = DB::table('inventory_transactions')
            ->where('reference_type', PurchaseReturn::class)->where('reference_id', $return->id)
            ->orderBy('id')->pluck('transaction_type')->all();
        $this->assertSame(['purchase_return', 'purchase_return_reversed'], $types);
    }

    public function test_a_return_that_moved_no_stock_restores_none(): void
    {
        // A PO line marked received with no stock row behind it: raising the
        // return finds nothing to decrement, so rejecting must not invent any.
        $product = Product::factory()->create();
        $line    = $this->line('product', $product->id, 3);
        $line->update(['quantity_received' => 3]);

        $this->raiseReturn($line, 1)->assertSuccessful();
        $return = PurchaseReturn::firstOrFail();

        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/reject", ['reason' => 'x'])
            ->assertOk();

        $this->assertSame(0, (int) InventoryItem::where('product_id', $product->id)->sum('quantity_on_hand'));
        $this->assertSame('3.00', (string) $line->fresh()->quantity_received);
    }

    public function test_the_same_goods_cannot_be_returned_twice(): void
    {
        $mat    = $this->material();
        $outlet = Outlet::factory()->create();
        $line   = $this->line('material', $mat->id, 1.00);
        $this->receive($line, 1.00, $outlet->id);

        $this->raiseReturn($line, 0.75)->assertSuccessful();
        // Only 0.25 m is left that hasn't already gone back.
        $this->raiseReturn($line, 0.75)->assertStatus(422);
        $this->raiseReturn($line, 0.25)->assertSuccessful();

        $this->assertEquals(0.00, $this->materialOnHand($mat));
        $this->assertSame(2, PurchaseReturn::count());
    }
}
