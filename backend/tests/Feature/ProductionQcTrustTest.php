<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderAssignee;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Production Cycle 8 (trust), the owner's decisions of 5 Oct 2026:
 *
 *  3. "Worked on the order" for the own-work QC rule (Policy 4) is anyone who
 *     ever held a stage or counted its pieces, plus order-level assignees,
 *     not only whoever holds a stage today.
 *  4. The second QC route (POST /approvals) gets the same checks as QC:
 *     visibility, own-work rule, and agreement with the order's status.
 *  5. The calendar shows a tailor her own jobs, not the whole floor.
 *
 * Fixture: a 2-piece order, one Stitching stage, held today by $tailor.
 * $sewer and $counter are admins (they hold QC rights) who touched it.
 * $inspector never touched it.
 */
class ProductionQcTrustTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;
    private User $inspector;
    private ProductionOrder $order;
    private ProductionTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->tailor    = $this->user('tailor');
        $this->inspector = $this->user('admin');
        [$this->order, $this->task] = $this->job('PRD-TRUST-1', $this->tailor, 'in_progress', 1);
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $u->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** @return array{0: ProductionOrder, 1: ProductionTask} */
    private function job(string $number, ?User $holder, string $status, int $done, ?Outlet $outlet = null): array
    {
        $o = ProductionOrder::create([
            'order_number' => $number, 'product_id' => Product::factory()->create()->id,
            'status' => $status, 'quantity' => 2, 'due_date' => now()->addDays(4)->toDateString(),
            'outlet_id' => $outlet?->id,
        ]);
        $stage = ProductionStage::firstOrCreate(['slug' => 'stitch-trust'], ['name' => 'Stitching', 'sort_order' => 1, 'is_active' => true]);
        $t = ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $o->id, 'production_stage_id' => $stage->id, 'assigned_to' => $holder?->id,
            'sequence' => 1, 'status' => $done >= 2 ? 'completed' : 'in_progress', 'quantity_done' => $done,
            'started_at' => now()->subHour(), 'completed_at' => $done >= 2 ? now() : null,
        ]);

        return [$o, $t];
    }

    /** The order reaches QC with no one signed in, as the floor hand-off would. */
    private function toQc(): void
    {
        auth()->forgetGuards();
        ProductionTask::withoutViewerScope()->whereKey($this->task->id)
            ->update(['status' => 'completed', 'quantity_done' => 2, 'completed_at' => now()]);
        ProductionOrder::whereKey($this->order->id)->update(['status' => 'qc_pending']);
    }

    private function passAs(User $u)
    {
        Sanctum::actingAs($u, ['*']);

        return $this->postJson("/api/v1/admin/production-orders/{$this->order->id}/qc", [
            'passed' => true, 'passed_quantity' => 2, 'failed_quantity' => 0,
        ]);
    }

    // ── 3. who counts as having worked on it ──────────────────────────────────

    public function test_a_manager_who_counted_pieces_cannot_pass_the_order(): void
    {
        $counter = $this->user('admin');
        Sanctum::actingAs($counter, ['*']);
        $this->postJson("/api/v1/tailor/tasks/{$this->task->id}/progress", ['quantity_done' => 2])->assertOk();
        $this->toQc();

        $this->passAs($counter)->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->passAs($this->inspector)->assertSuccessful();
    }

    public function test_someone_moved_off_a_stage_cannot_pass_the_order(): void
    {
        // The sewer held Stitching; a manager moved it to the tailor through
        // the task edit form, which writes no event of its own.
        $sewer   = $this->user('admin');
        $planner = $this->user('admin');
        ProductionTask::withoutViewerScope()->whereKey($this->task->id)->update(['assigned_to' => $sewer->id]);

        Sanctum::actingAs($planner, ['*']);
        $this->putJson("/api/v1/admin/production-tasks/{$this->task->id}", ['assigned_to' => $this->tailor->id])->assertOk();
        $this->toQc();

        $this->passAs($sewer)->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        // Assigning work is not doing it.
        $this->passAs($planner)->assertSuccessful();
    }

    public function test_someone_moved_off_by_the_reassign_action_cannot_pass_the_order(): void
    {
        $sewer = $this->user('admin');
        ProductionTask::withoutViewerScope()->whereKey($this->task->id)->update(['assigned_to' => $sewer->id]);

        Sanctum::actingAs($this->inspector, ['*']);
        $this->putJson("/api/v1/admin/production-tasks/{$this->task->id}/reassign", ["tailor_id" => $this->tailor->id])->assertOk();
        $this->toQc();

        $this->passAs($sewer)->assertStatus(403);
    }

    public function test_an_order_level_assignee_cannot_pass_the_order(): void
    {
        $lead = $this->user('admin');
        ProductionOrderAssignee::create(['production_order_id' => $this->order->id, 'user_id' => $lead->id, 'role_in_order' => 'assignee']);
        $this->toQc();

        $this->passAs($lead)->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
    }

    public function test_the_manager_who_sent_it_back_can_inspect_the_redo(): void
    {
        // Lowering a count is a rework send-back, not making.
        $this->toQc();
        Sanctum::actingAs($this->inspector, ['*']);
        $this->postJson("/api/v1/admin/production-orders/{$this->order->id}/qc", [
            'passed' => false, 'passed_quantity' => 1, 'failed_quantity' => 1,
        ])->assertSuccessful();
        $this->postJson("/api/v1/admin/production-orders/{$this->order->id}/rework", [
            'reason' => 'Uneven hem', 'stages' => [['task_id' => $this->task->id, 'pieces' => 1]],
        ])->assertOk();

        Sanctum::actingAs($this->tailor, ['*']);
        $this->postJson("/api/v1/tailor/tasks/{$this->task->id}/progress", ['quantity_done' => 2])->assertOk();
        $this->assertSame('qc_pending', $this->order->fresh()->status);

        $this->passAs($this->inspector)->assertSuccessful();
    }

    // ── 4. the second QC route ────────────────────────────────────────────────

    private function signOff(User $u, string $gate, ?ProductionOrder $o = null)
    {
        Sanctum::actingAs($u, ['*']);

        return $this->postJson('/api/v1/admin/production-orders/' . ($o ?? $this->order)->id . '/approvals', ['gate' => $gate]);
    }

    public function test_a_qc_sign_off_must_match_the_orders_qc_result(): void
    {
        $this->toQc();   // qc_pending: nothing has passed yet
        $this->signOff($this->inspector, 'qc_passed')->assertStatus(422);
        $this->signOff($this->inspector, 'qc_failed')->assertStatus(422);

        ProductionOrder::whereKey($this->order->id)->update(['status' => 'qc_passed']);
        $this->signOff($this->inspector, 'qc_passed')->assertStatus(201);
    }

    public function test_a_maker_cannot_sign_off_qc_through_the_second_route(): void
    {
        $sewer = $this->user('admin');
        ProductionTask::withoutViewerScope()->whereKey($this->task->id)->update(['assigned_to' => $sewer->id]);
        ProductionOrder::whereKey($this->order->id)->update(['status' => 'qc_passed']);

        $this->signOff($sewer, 'qc_passed')->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        // Gates that are not QC are not a maker's conflict.
        $this->signOff($sewer, 'dispatched')->assertStatus(201);
    }

    public function test_the_second_route_only_reaches_orders_the_signer_can_open(): void
    {
        $mine  = Outlet::factory()->create();
        $other = Outlet::factory()->create();
        $manager = $this->user('outlet_manager', $mine);
        [$elsewhere] = $this->job('PRD-TRUST-ELSE', null, 'qc_passed', 2, $other);

        $this->signOff($manager, 'qc_passed', $elsewhere)->assertStatus(404);
        $this->signOff($manager, 'dispatched', $elsewhere)->assertStatus(404);
    }

    // ── 5. the calendar ──────────────────────────────────────────────────────

    public function test_the_calendar_shows_a_tailor_only_her_own_jobs(): void
    {
        $colleague = $this->user('tailor');
        $this->job('PRD-TRUST-THEIRS', $colleague, 'in_progress', 0);

        Sanctum::actingAs($this->tailor, ['*']);
        $res = $this->getJson('/api/v1/admin/production/schedule')->assertOk();
        $numbers = collect($res->json('upcoming_orders'))->pluck('order_number');

        $this->assertSame(['PRD-TRUST-1'], $numbers->all());
        $this->assertSame(1, $res->json('active_count'));

        // The floor's managers still see the whole board.
        Sanctum::actingAs($this->inspector, ['*']);
        $all = collect($this->getJson('/api/v1/admin/production/schedule')->json('upcoming_orders'))->pluck('order_number');
        $this->assertEqualsCanonicalizing(['PRD-TRUST-1', 'PRD-TRUST-THEIRS'], $all->all());
    }
}
