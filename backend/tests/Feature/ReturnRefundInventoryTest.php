<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: ReturnController::processRefund restored stock with a raw insert
 * into inventory_transactions using columns that don't exist (inventory_id,
 * type, quantity, performed_by, updated_at — the schema has inventory_item_id,
 * transaction_type, quantity_change, created_by, created_at), after querying
 * `inventories` by variant_id/location_type — columns that don't exist either.
 * It also wrote refund_notes/refunded_by/completed_at to order_returns (none
 * exist) and read a phantom order_returns.total_amount, so every refund 500'd
 * on the first UPDATE. The rewrite restores stock on the inventory_items
 * ledger, resolved exactly like POS commit/void.
 */
class ReturnRefundInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('orders.manage_returns', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->admin);
    }

    /** @return array{0:\App\Models\InventoryItem,1:int,2:\App\Models\OrderItem} outlet-pinned stock row, return id, order item */
    private function seedReturn(bool $restock = true, int $qty = 2): array
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();
        $item    = InventoryItem::factory()->create([
            'product_id'       => $product->id,
            'outlet_id'        => $outlet->id,
            'quantity_on_hand' => 10,
        ]);

        $order = Order::factory()->create(['outlet_id' => $outlet->id]);
        $orderItem = OrderItem::create([
            'order_id'          => $order->id,
            'product_id'        => $product->id,
            'sku'               => 'SKU-RET-1',
            'product_name'      => 'Returnable',
            'quantity'          => $qty,
            'unit_price'        => 500,
            'total_price'       => 500 * $qty,
            'inventory_item_id' => $item->id,
        ]);

        $returnId = DB::table('order_returns')->insertGetId([
            'return_number' => 'RET-TEST-' . uniqid(),
            'order_id'      => $order->id,
            'status'        => 'received',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('return_items')->insert([
            'return_id'     => $returnId,
            'order_item_id' => $orderItem->id,
            'quantity'      => $qty,
            'restock'       => $restock,
            'created_at'    => now(),
        ]);

        return [$item, $returnId, $orderItem];
    }

    public function test_process_refund_restocks_and_writes_a_valid_ledger_row(): void
    {
        [$item, $returnId] = $this->seedReturn();

        $this->postJson("/api/v1/admin/returns/{$returnId}/process-refund", [
            'refund_amount' => 100,
            'refund_method' => 'store_credit',
            'notes'         => 'defective zip',
        ])->assertOk();

        $this->assertSame(12, $item->fresh()->quantity_on_hand);

        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'transaction_type'  => 'return',
            'reference_type'    => 'order_return',
            'reference_id'      => $returnId,
            'quantity_change'   => 2,
            'quantity_before'   => 10,
            'quantity_after'    => 12,
            'created_by'        => $this->admin->id,
        ]);

        $return = DB::table('order_returns')->find($returnId);
        $this->assertSame('completed', $return->status);
        $this->assertSame(100.0, (float) $return->refund_amount);
        $this->assertNotNull($return->refunded_at);
        $this->assertStringContainsString('defective zip', (string) $return->admin_notes);
    }

    public function test_lines_flagged_no_restock_stay_out_of_stock(): void
    {
        [$item, $returnId] = $this->seedReturn(restock: false);

        $this->postJson("/api/v1/admin/returns/{$returnId}/process-refund", [
            'refund_amount' => 100,
            'refund_method' => 'store_credit',
        ])->assertOk();

        $this->assertSame(10, $item->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_refund_above_the_return_total_is_rejected(): void
    {
        [, $returnId] = $this->seedReturn(qty: 2); // total = 2 × 500 = 1000

        $this->postJson("/api/v1/admin/returns/{$returnId}/process-refund", [
            'refund_amount' => 1500,
            'refund_method' => 'store_credit',
        ])->assertStatus(422);

        $this->assertSame('received', DB::table('order_returns')->find($returnId)->status);
    }
}
