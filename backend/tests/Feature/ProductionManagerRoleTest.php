<?php

namespace Tests\Feature;

use App\Models\Notification as NotificationRow;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\CustomerContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Production Manager (owner's ask, 2026-10-15): runs the floor — sees
 * every production order, assigns and reassigns the tailors, confirms work
 * into the queue, inspects and signs off quality — and reaches nothing
 * outside production. No BOMs (they carry material costs), no costing or
 * margins, no sales orders, payments, customers, procurement, stock ledgers
 * or expenses, and not the tailor's own workspace.
 */
class ProductionManagerRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function orderRaisedBy(User $raiser): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PRD-PM-' . uniqid(),
            'product_id'   => Product::factory()->create()->id,
            'quantity'     => 4,
            'status'       => 'in_progress',
            'due_date'     => now()->addDays(3),
            'created_by'   => $raiser->id,
        ]);
    }

    public function test_the_role_exists_and_is_floor_wide(): void
    {
        $pm      = $this->userWithRole('production_manager');
        $someone = User::factory()->create();
        $order   = $this->orderRaisedBy($someone);

        Sanctum::actingAs($pm);

        // Every order on the floor, not only ones they raised.
        $numbers = collect($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data'))
            ->pluck('order_number');
        $this->assertTrue($numbers->contains($order->order_number), 'A production manager must see the whole floor.');

        $this->getJson("/api/v1/admin/production-orders/{$order->id}")->assertOk();
        $this->getJson('/api/v1/admin/production/schedule')->assertOk();
        $this->getJson('/api/v1/admin/production-tasks')->assertOk();
        // Who is carrying how much — the screen assignment decisions are made from.
        $this->getJson('/api/v1/admin/intelligence/tailor-workload')->assertOk();
        // The production report page, but not its money.
        $this->getJson('/api/v1/admin/reports/production/summary')->assertOk();
        $this->getJson('/api/v1/admin/reports/production/costing-summary')->assertForbidden();
    }

    public function test_the_role_reaches_nothing_outside_production(): void
    {
        $pm = $this->userWithRole('production_manager');
        Sanctum::actingAs($pm);

        foreach ([
            '/api/v1/admin/orders',            // sales orders
            '/api/v1/admin/payments',
            '/api/v1/admin/customers',
            '/api/v1/admin/expenses',
            '/api/v1/admin/purchase-orders',   // procurement
            '/api/v1/admin/reports/sales/summary',
        ] as $url) {
            $status = $this->getJson($url)->status();
            $this->assertContains($status, [403, 404], "{$url} answered {$status} to a production manager.");
        }

        $role = Role::findByName('production_manager', 'sanctum');
        foreach ([
            'production.view_bom', 'production.delete_order', 'production.worker',
            'procurement.view', 'inventory.view', 'payments.view', 'expenses.view',
            'orders.view', 'customers.view', 'products.view_cost',
            'reports.financial', 'reports.sales', 'reports.export',
        ] as $withheld) {
            $this->assertFalse($role->hasPermissionTo($withheld), "production_manager holds {$withheld}.");
        }
    }

    public function test_bom_costs_stay_closed(): void
    {
        $pm = $this->userWithRole('production_manager');
        Sanctum::actingAs($pm);

        $product = Product::factory()->create();
        $this->getJson("/api/v1/admin/products/{$product->id}/bom")->assertForbidden();
    }

    public function test_a_production_manager_can_unlock_a_stage(): void
    {
        // The unlock route used to sit inside the floor-worker group, so it
        // also demanded production.worker — which no manager role holds.
        $pm    = $this->userWithRole('production_manager');
        $order = $this->orderRaisedBy(User::factory()->create());
        $stage = ProductionStage::create(['name' => 'Embroidery', 'slug' => 'embroidery-' . uniqid(), 'is_active' => true, 'sort_order' => 1]);
        $task  = ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $order->id, 'production_stage_id' => $stage->id,
            'status' => 'pending', 'sequence' => 2,
        ]);

        Sanctum::actingAs($pm);
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/unlock", ['allow' => true])->assertOk();
        $this->assertTrue((bool) $task->fresh()->concurrent_allowed);

        // …and an outlet manager, for the same reason, now can too — on an
        // order at their own outlet (they are outlet-scoped; elsewhere 404).
        $outlet = \App\Models\Outlet::factory()->create();
        $order->update(['outlet_id' => $outlet->id]);
        $om = $this->userWithRole('outlet_manager');
        DB::table('outlet_user')->insert(['outlet_id' => $outlet->id, 'user_id' => $om->id]);
        Sanctum::actingAs($om);
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/unlock", ['allow' => false])->assertOk();

        // A tailor still cannot — unlocking is a manager's call.
        Sanctum::actingAs($this->userWithRole('tailor'));
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/unlock", ['allow' => true])->assertForbidden();
    }

    public function test_the_floors_alerts_reach_the_production_manager(): void
    {
        $pm = $this->userWithRole('production_manager');

        NotificationService::productionOverdue(1, 'PRD-ALERT-1', 'Cassock', now()->subDay()->toDateString());
        NotificationService::productionQcFailed(1, 'PRD-ALERT-1', 'Cassock', 2, 'Hem uneven');

        $titles = DB::table('notifications')->where('notifiable_id', $pm->id)->pluck('data')
            ->map(fn ($d) => json_decode($d, true)['title'] ?? '')->implode(' | ');
        $this->assertStringContainsString('PRD-ALERT-1', $titles, 'Overdue and QC alerts must reach whoever runs the floor.');
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $pm->id)->count());
    }

    public function test_customer_contacts_are_read_in_full(): void
    {
        $pm = $this->userWithRole('production_manager');

        $policy = CustomerContacts::policyFor($pm, 'production.view');
        $this->assertSame(CustomerContacts::FULL, $policy['contacts']);
        $this->assertSame(CustomerContacts::FULL, $policy['address']);
    }
}
