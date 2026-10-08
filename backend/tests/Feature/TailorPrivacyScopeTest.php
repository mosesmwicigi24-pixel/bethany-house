<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionAutoAssigneeRule;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderAssignee;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tailor View Cycle 2 (C2-2): what a tailor's token no longer receives.
 * Colleagues' emails (assignee lists, @mention search, task history),
 * other orders' material allocations, and the all-tailors workload feed.
 * Owner decision 2026-10-05: names for everyone, emails only for people who
 * may list staff accounts (users.view).
 */
class TailorPrivacyScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;
    private User $colleague;
    private ProductionOrder $hers;
    private ProductionOrder $notHers;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->mary = $this->user('tailor', 'Mary', 'Wanjiku', 'mary@bh.test');
        $this->colleague = $this->user('tailor', 'John', 'Otieno', 'john.otieno@bh.test');

        $stage = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitch-priv', 'sort_order' => 1, 'is_active' => true]);
        $this->hers    = $this->order('PRD-PRIV-HERS');
        $this->notHers = $this->order('PRD-PRIV-OTHER');
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $this->hers->id, 'production_stage_id' => $stage->id,
            'assigned_to' => $this->mary->id, 'sequence' => 1, 'status' => 'pending', 'quantity_done' => 0,
        ]);
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $this->notHers->id, 'production_stage_id' => $stage->id,
            'assigned_to' => $this->colleague->id, 'sequence' => 1, 'status' => 'pending', 'quantity_done' => 0,
        ]);
    }

    private function user(string $role, string $first = 'Test', string $last = 'User', ?string $email = null): User
    {
        $u = User::factory()->create(array_filter(['first_name' => $first, 'last_name' => $last, 'email' => $email]));
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function order(string $number): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => $number, 'product_id' => Product::factory()->create()->id,
            'status' => 'pending', 'quantity' => 1,
        ]);
    }

    public function test_assignee_lists_name_colleagues_without_their_email(): void
    {
        ProductionAutoAssigneeRule::create(['user_id' => $this->colleague->id, 'role_in_order' => 'observer', 'is_active' => true]);
        ProductionOrderAssignee::create(['production_order_id' => $this->hers->id, 'user_id' => $this->colleague->id, 'role_in_order' => 'observer']);

        Sanctum::actingAs($this->mary);
        $rules = $this->getJson('/api/v1/admin/production/auto-assignees')->assertOk()->json();
        $this->assertSame('John', $rules[0]['user']['first_name']);
        $this->assertArrayNotHasKey('email', $rules[0]['user']);

        $assignees = $this->getJson("/api/v1/admin/production-orders/{$this->hers->id}/assignees")->assertOk()->json();
        $this->assertSame('Otieno', $assignees[0]['user']['last_name']);
        $this->assertArrayNotHasKey('email', $assignees[0]['user']);

        // Someone who may list staff accounts still sees them.
        Sanctum::actingAs($this->user('admin'));
        $this->assertSame('john.otieno@bh.test', $this->getJson('/api/v1/admin/production/auto-assignees')->json('0.user.email'));
        $this->assertSame('john.otieno@bh.test', $this->getJson("/api/v1/admin/production-orders/{$this->hers->id}/assignees")->json('0.user.email'));
    }

    public function test_the_mention_search_finds_by_name_and_never_by_email(): void
    {
        Sanctum::actingAs($this->mary);

        $byName = $this->getJson('/api/v1/admin/comments/users?q=Otien')->assertOk()->json('users');
        $this->assertSame(['John Otieno'], array_column($byName, 'name'));
        $this->assertArrayNotHasKey('email', $byName[0]);

        // An address she cannot see must not be confirmable by searching for it.
        $this->assertSame([], $this->getJson('/api/v1/admin/comments/users?q=john.otieno@')->assertOk()->json('users'));

        Sanctum::actingAs($this->user('admin'));
        $hit = $this->getJson('/api/v1/admin/comments/users?q=john.otieno@')->assertOk()->json('users');
        $this->assertSame('john.otieno@bh.test', $hit[0]['email']);
    }

    public function test_task_history_never_falls_back_to_a_colleagues_email(): void
    {
        $task = ProductionTask::withoutViewerScope()->where('assigned_to', $this->mary->id)->first();
        $nameless = User::factory()->create(['first_name' => '', 'last_name' => '', 'email' => 'nameless@bh.test']);
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'Task started', 'event' => 'production_task_status_updated',
            'causer_type' => User::class, 'causer_id' => $nameless->id,
            'properties' => json_encode(['task_id' => $task->id, 'action' => 'start']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->mary);
        $body = $this->getJson("/api/v1/tailor/tasks/{$task->id}/history")->assertOk()->getContent();

        $this->assertStringNotContainsString('nameless@bh.test', $body);
        $this->assertStringContainsString('Staff member', $body);
    }

    public function test_material_allocations_list_only_her_orders(): void
    {
        $material = DB::table('materials')->insertGetId([
            'code' => 'FAB-PRIV', 'name' => 'Wool', 'unit_of_measure' => 'm',
            'unit_cost' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->hers, $this->notHers] as $po) {
            DB::table('material_allocations')->insert([
                'production_order_id' => $po->id, 'material_id' => $material,
                'quantity_required' => 2, 'quantity_allocated' => 0, 'quantity_used' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->mary);
        $orders = collect($this->getJson('/api/v1/admin/material-allocations')->assertOk()->json('data'))
            ->pluck('production_order_id')->all();
        $this->assertSame([$this->hers->id], $orders);

        Sanctum::actingAs($this->user('admin'));
        $this->assertCount(2, $this->getJson('/api/v1/admin/material-allocations')->assertOk()->json('data'));
    }

    public function test_the_all_tailors_workload_feed_is_for_people_who_assign_work(): void
    {
        Sanctum::actingAs($this->mary);
        $this->getJson('/api/v1/admin/intelligence/tailor-workload')->assertForbidden();

        Sanctum::actingAs($this->user('outlet_manager'));
        $names = array_column($this->getJson('/api/v1/admin/intelligence/tailor-workload')->assertOk()->json('tailors'), 'name');
        $this->assertContains('Mary Wanjiku', $names);
    }
}
