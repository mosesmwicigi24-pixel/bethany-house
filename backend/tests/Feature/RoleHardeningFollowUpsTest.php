<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Material;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D — the follow-ups found in Phases 1–2.
 */
class RoleHardeningFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
    }

    private function catalogueUser(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findByName($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    /** A user whose ONLY grants are these — no role name the code could match. */
    private function grantedOnly(array $permissions): User
    {
        $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
        foreach ($permissions as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    // ── materials: unit cost needs products.view_cost ───────────────────────

    public function test_materials_carry_cost_only_for_cost_viewers(): void
    {
        $m = Material::create(['code' => 'M-1', 'name' => 'Gold brocade', 'category' => 'fabric',
            'unit_of_measure' => 'm', 'unit_cost' => 850, 'reorder_point' => 0, 'is_active' => true]);

        Sanctum::actingAs($this->grantedOnly(['inventory.view']));
        foreach ([
            $this->getJson('/api/v1/admin/inventory/materials')->assertOk()->json('data.0'),
            $this->getJson("/api/v1/admin/inventory/materials/{$m->id}")->assertOk()->json('material'),
        ] as $row) {
            foreach (['unit_cost', 'cost_per_unit', 'stock_value'] as $key) {
                $this->assertArrayNotHasKey($key, $row, "{$key} reached a viewer without products.view_cost");
            }
        }

        // ...and cannot overwrite the figure they are not shown.
        Sanctum::actingAs($this->grantedOnly(['inventory.view', 'inventory.adjust']));
        $this->putJson("/api/v1/admin/inventory/materials/{$m->id}", ['name' => 'Gold brocade II', 'unit_cost' => 1])->assertOk();
        $this->assertEquals(850, (float) $m->fresh()->unit_cost);
        $this->assertSame('Gold brocade II', $m->fresh()->name);

        Sanctum::actingAs($this->grantedOnly(['inventory.view', 'products.view_cost']));
        $row = $this->getJson('/api/v1/admin/inventory/materials')->assertOk()->json('data.0');
        $this->assertEquals(850, $row['unit_cost']);
    }

    // ── production sub-endpoints follow the order's visibility ──────────────

    public function test_production_sub_endpoints_and_pdf_are_scoped_like_the_order(): void
    {
        $me    = $this->grantedOnly(['production.view']);
        $stage = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching', 'sort_order' => 1, 'is_active' => true]);
        $prod  = Product::factory()->create();
        $mine  = ProductionOrder::create(['order_number' => 'PRD-MINE',  'product_id' => $prod->id, 'quantity' => 1, 'status' => 'in_progress']);
        $other = ProductionOrder::create(['order_number' => 'PRD-OTHER', 'product_id' => $prod->id, 'quantity' => 1, 'status' => 'in_progress']);
        ProductionTask::create(['production_order_id' => $mine->id, 'production_stage_id' => $stage->id, 'status' => 'pending', 'assigned_to' => $me->id]);
        Sanctum::actingAs($me);

        foreach (['timeline', 'messages', 'assignees', 'audit-log', 'approvals'] as $sub) {
            $this->getJson("/api/v1/admin/production-orders/{$other->id}/{$sub}")
                ->assertNotFound();
        }
        $this->postJson("/api/v1/admin/production-orders/{$other->id}/messages", ['message' => 'hello'])->assertNotFound();
        $this->get("/api/v1/admin/pdf/production-orders/{$other->id}")->assertNotFound();

        $this->getJson("/api/v1/admin/production-orders/{$mine->id}/timeline")->assertOk();
    }

    // ── payment approve without notes ───────────────────────────────────────

    public function test_a_payment_approves_without_notes(): void
    {
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'pending_approval', 'total_amount' => 1000]);
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'bank_transfer', 'amount' => 1000,
            'currency_code' => 'KES', 'status' => 'pending', 'requires_approval' => true,
            'approval_status' => 'pending_review',
        ]);

        Sanctum::actingAs($this->catalogueUser('finance_manager'));
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertOk();
        $this->assertSame('approved', $payment->fresh()->approval_status);
    }

    // ── dead cash-register routes are gone ──────────────────────────────────

    public function test_the_dead_cash_register_write_routes_no_longer_exist(): void
    {
        $manager = $this->catalogueUser('outlet_manager');   // pos.cash_management
        Sanctum::actingAs($manager);

        foreach (['deposit', 'withdrawal', 'adjustment'] as $what) {
            // They pointed at controller methods that never existed: a 500.
            $this->postJson("/api/v1/pos/cash-register/{$what}", ['amount' => 100])->assertNotFound();
        }
    }

    // ── stock-adjustment reverse: not by the person who approved it ─────────

    public function test_whoever_approved_an_adjustment_cannot_reverse_it(): void
    {
        $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);
        Sanctum::actingAs($this->catalogueUser('outlet_manager'));
        $id = $this->postJson('/api/v1/admin/inventory/adjustments', [
            'inventory_item_id' => $item->id, 'quantity_change' => 5,
            'reason_code' => 'correction', 'notes' => 'Counted again',
        ])->assertCreated()->json('adjustment.id');

        $approver = $this->catalogueUser('procurement_manager');
        Sanctum::actingAs($approver);
        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/approve")->assertOk();

        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/reverse")
            ->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame(15, (int) $item->fresh()->quantity_on_hand);

        Sanctum::actingAs($this->catalogueUser('procurement_manager'));
        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/reverse")->assertSuccessful();
        $this->assertSame(10, (int) $item->fresh()->quantity_on_hand);
    }

    // ── role-name checks → permissions ──────────────────────────────────────

    public function test_working_across_outlets_is_a_permission_not_a_role_name(): void
    {
        $a = Outlet::factory()->create(['is_active' => true]);
        $b = Outlet::factory()->create(['is_active' => true]);

        // Catalogue admin keeps what the role check gave it.
        Sanctum::actingAs($this->catalogueUser('admin'));
        $this->assertCount(2, $this->getJson('/api/v1/admin/time-clock/outlets')->assertOk()->json('data'));

        // Any role granted outlets.all_access gets the same — no role name involved.
        $floater = $this->grantedOnly(['outlets.all_access', 'pos.access']);
        Sanctum::actingAs($floater);
        $this->assertCount(2, $this->getJson('/api/v1/admin/time-clock/outlets')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/admin/pos/outlets')->assertOk()->json('data'));

        // An outlet manager stays on their assigned outlet.
        $manager = $this->catalogueUser('outlet_manager');
        $manager->outlets()->attach($a->id);
        Sanctum::actingAs($manager);
        $this->assertSame([$a->id], collect($this->getJson('/api/v1/admin/time-clock/outlets')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_acting_on_someone_elses_task_follows_manage_assignees(): void
    {
        $stage  = ProductionStage::create(['name' => 'Cutting', 'slug' => 'cutting', 'sort_order' => 1, 'is_active' => true]);
        $order  = ProductionOrder::create(['order_number' => 'PRD-1', 'product_id' => Product::factory()->create()->id, 'quantity' => 4, 'status' => 'in_progress']);
        $tailor = $this->catalogueUser('tailor');
        $task   = ProductionTask::create(['production_order_id' => $order->id, 'production_stage_id' => $stage->id, 'status' => 'in_progress', 'assigned_to' => $tailor->id]);

        // A coordinator (manage_assignees) with worker access, under no admin role name.
        Sanctum::actingAs($this->grantedOnly(['production.view', 'production.worker', 'production.manage_assignees']));
        $this->getJson("/api/v1/tailor/tasks/{$task->id}/history")->assertOk();

        // A fellow worker without it is still refused.
        Sanctum::actingAs($this->catalogueUser('tailor'));
        $this->assertContains($this->getJson("/api/v1/tailor/tasks/{$task->id}/history")->status(), [403, 404]);
    }
}
