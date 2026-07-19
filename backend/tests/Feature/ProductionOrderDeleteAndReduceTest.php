<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Privileged production-order edit/delete (production.delete_order).
 *
 * A confirmed order's quantity is structural, but a privileged holder may
 * REDUCE it (never below work done, never an increase) — surplus serials void,
 * material demand resizes — and may HARD-DELETE the order (children cascade,
 * the linked sales item unlinks). Routine roles keep the draft-only rule.
 */
class ProductionOrderDeleteAndReduceTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $perms): User
    {
        $user = User::factory()->create();
        foreach (array_merge(['production.view'], $perms) as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function confirmedOrder(int $qty = 80): ProductionOrder
    {
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-DR-' . $product->id,
            'product_id'   => $product->id,
            'quantity'     => $qty,
            'status'       => 'pending',
        ]);
        // One in-production serial per unit, as confirmation mints them.
        for ($i = 1; $i <= $qty; $i++) {
            ProductSerial::create([
                'serial_number'       => "{$order->order_number}-{$i}",
                'product_id'          => $product->id,
                'production_order_id' => $order->id,
                'status'              => ProductSerial::IN_PRODUCTION,
            ]);
        }
        return $order;
    }

    public function test_a_privileged_user_can_reduce_a_confirmed_order_and_serials_follow(): void
    {
        $this->user(['production.raise_order', 'production.delete_order']);
        $order = $this->confirmedOrder(80);

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 50])
            ->assertOk();

        $this->assertSame(50, (int) $order->fresh()->quantity);
        $this->assertSame(50, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_PRODUCTION)->count());
        $this->assertSame(30, ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::CANCELLED)->count());
    }

    public function test_a_routine_role_still_cannot_change_a_confirmed_quantity(): void
    {
        $this->user(['production.raise_order']);
        $order = $this->confirmedOrder(80);

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 50])
            ->assertStatus(422);
        $this->assertSame(80, (int) $order->fresh()->quantity);
    }

    public function test_an_increase_is_refused_even_for_a_privileged_user(): void
    {
        $this->user(['production.raise_order', 'production.delete_order']);
        $order = $this->confirmedOrder(80);

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 120])
            ->assertStatus(422);
        $this->assertSame(80, (int) $order->fresh()->quantity);
    }

    public function test_cannot_reduce_below_pieces_already_worked(): void
    {
        $this->user(['production.raise_order', 'production.delete_order']);
        $order = $this->confirmedOrder(80);
        $order->tasks()->create([
            'production_stage_id' => \App\Models\ProductionStage::create(['name' => 'Cutting', 'slug' => 'dr-cut', 'sort_order' => 1, 'is_active' => true])->id,
            'status' => 'in_progress', 'sequence' => 1, 'quantity_done' => 30,
        ]);

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 20])
            ->assertStatus(422);
        $this->assertSame(80, (int) $order->fresh()->quantity);
    }

    public function test_force_delete_removes_the_order_and_unlinks_the_sales_item(): void
    {
        $this->user(['production.raise_order', 'production.delete_order']);
        $order = $this->confirmedOrder(80);
        $order->tasks()->create([
            'production_stage_id' => \App\Models\ProductionStage::create(['name' => 'Cut', 'slug' => 'dr-c2', 'sort_order' => 1, 'is_active' => true])->id,
            'status' => 'pending', 'sequence' => 1,
        ]);

        // A sales-order line points at this production order — it must survive, unlinked.
        $salesOrder = \App\Models\Order::factory()->create(['order_type' => 'pos', 'currency_code' => 'KES']);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $salesOrder->id, 'product_id' => $order->product_id, 'sku' => 'SKU-DR',
            'product_name' => 'Cassock', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100,
            'production_order_id' => $order->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/admin/production-orders/{$order->id}/force")->assertOk();

        $this->assertDatabaseMissing('production_orders', ['id' => $order->id]);
        $this->assertSame(0, DB::table('production_tasks')->where('production_order_id', $order->id)->count());
        $this->assertNull(DB::table('order_items')->where('id', $itemId)->value('production_order_id'));
    }

    public function test_force_delete_needs_the_permission(): void
    {
        $this->user(['production.raise_order']); // no delete_order
        $order = $this->confirmedOrder(10);

        $this->deleteJson("/api/v1/admin/production-orders/{$order->id}/force")->assertStatus(403);
        $this->assertDatabaseHas('production_orders', ['id' => $order->id]);
    }

    public function test_a_completed_order_cannot_be_deleted(): void
    {
        $this->user(['production.raise_order', 'production.delete_order']);
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-DR-DONE', 'product_id' => $product->id,
            'quantity' => 5, 'status' => 'completed', 'completed_at' => now(),
        ]);

        $this->deleteJson("/api/v1/admin/production-orders/{$order->id}/force")->assertStatus(422);
        $this->assertDatabaseHas('production_orders', ['id' => $order->id]);
    }
}
