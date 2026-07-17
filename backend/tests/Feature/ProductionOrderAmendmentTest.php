<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Amending a production order.
 *
 * PUT /production-orders/{id} existed but let quantity be edited at ANY
 * non-completed status. Quantity is structural: confirmation mints one serial
 * per unit and material allocations are sized from it, so a post-confirm edit
 * left the order silently disagreeing with its own serials and materials.
 * Quantity now amends only while draft; schedule/priority/notes amend any time
 * before completion.
 */
class ProductionOrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRaiser(): User
    {
        $user = User::factory()->create();
        // The production route GROUP is gated production.view on top of the
        // per-route production.raise_order — both are needed to reach update().
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.raise_order', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function order(string $status, int $quantity = 30): ProductionOrder
    {
        $product = Product::factory()->create();

        return ProductionOrder::create([
            'order_number' => 'PRD-AMEND-' . $status . '-' . $product->id,
            'product_id'   => $product->id,
            'quantity'     => $quantity,
            'status'       => $status,
        ]);
    }

    public function test_a_draft_quantity_can_be_amended_and_requirements_follow(): void
    {
        $this->actingAsRaiser();
        $order = $this->order('draft');

        // An untouched allocation sized for qty 30 at 2 units of material each.
        // (Real table names: bills_of_materials / bom_items; materials keys on code.)
        $materialId = DB::table('materials')->insertGetId([
            'code' => 'MAT-TF-1', 'name' => 'Test Fabric', 'unit_of_measure' => 'm',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bomId = DB::table('bills_of_materials')->insertGetId([
            'product_id' => $order->product_id, 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bom_items')->insert([
            'bom_id' => $bomId, 'material_id' => $materialId, 'quantity' => 2,
            'unit_of_measure' => 'm', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('material_allocations')->insert([
            'production_order_id' => $order->id, 'material_id' => $materialId,
            'quantity_required' => 60, 'quantity_allocated' => 0, 'quantity_used' => 0,
            'quantity_returned' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 50])
            ->assertOk();

        $this->assertSame(50, $order->fresh()->quantity);
        // 2 units × 50 = 100: the requirement followed the amendment.
        $this->assertEquals(100, DB::table('material_allocations')
            ->where('production_order_id', $order->id)->value('quantity_required'));
    }

    public function test_quantity_is_locked_once_the_order_is_confirmed(): void
    {
        $this->actingAsRaiser();
        $order = $this->order('pending');

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['quantity' => 50])
            ->assertStatus(422);

        $this->assertSame(30, $order->fresh()->quantity);
    }

    public function test_schedule_and_priority_amend_even_in_progress(): void
    {
        $this->actingAsRaiser();
        $order = $this->order('in_progress');

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", [
            'priority' => 'urgent',
            'due_date' => '2026-09-01',
            'notes'    => 'Customer moved the ordination forward.',
        ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('urgent', $fresh->priority);
        $this->assertSame('Customer moved the ordination forward.', $fresh->notes);
    }

    public function test_completed_orders_cannot_be_amended(): void
    {
        $this->actingAsRaiser();
        $order = $this->order('completed');

        $this->putJson("/api/v1/admin/production-orders/{$order->id}", ['priority' => 'high'])
            ->assertStatus(422);
    }

    public function test_sending_the_same_quantity_is_not_treated_as_a_change(): void
    {
        $this->actingAsRaiser();
        $order = $this->order('in_progress');

        // Frontends often send the whole form back. An unchanged quantity on a
        // confirmed order must not trip the draft-only rule.
        $this->putJson("/api/v1/admin/production-orders/{$order->id}", [
            'quantity' => 30,
            'priority' => 'high',
        ])->assertOk();

        $this->assertSame('high', $order->fresh()->priority);
    }
}
