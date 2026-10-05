<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Floor safety for the tailor view (PR 1 of the tailoring programme).
 *
 * Every test here uses DIFFERENT tailors on DIFFERENT stages of the same order,
 * with the real `tailor` role from permission:sync (data_scope = own). The
 * older gating tests used one tailor who owned every task with full scope,
 * which is exactly why the stage gate failing open for real tailors (audit B1)
 * was never caught.
 *
 * Covered:
 *   B1  the stage gate, piece ceiling/floor and the "all stages done → QC"
 *       hand-off read the whole pipeline, not the caller's slice of it
 *   B2  an order outside pending / in_progress / on_hold refuses floor work,
 *       from tailors and managers alike; finished goods enter stock once
 *   B10 re-assigning a task keeps its state
 *   F1  a tailor's note lands in the order's chat thread
 *       + order chat is open only to people who can see the order
 *   B-start  resuming keeps the original start time
 */
class TailorFloorSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function tailor(): User
    {
        return $this->userWithRole('tailor');
    }

    private function manager(): User
    {
        return $this->userWithRole('admin');
    }

    private function stage(string $slug, string $name, int $order): ProductionStage
    {
        return ProductionStage::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => $order, 'is_active' => true],
        );
    }

    private function order(int $quantity = 1, string $status = 'in_progress'): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PRD-T-' . fake()->unique()->numerify('######'),
            'product_id'   => Product::factory()->create()->id,
            'status'       => $status,
            'quantity'     => $quantity,
            'started_at'   => in_array($status, ['draft', 'pending']) ? null : now(),
        ]);
    }

    private function task(
        ProductionOrder $po,
        ProductionStage $stage,
        ?User $assignee,
        int $sequence,
        string $status = 'pending',
        int $done = 0,
    ): ProductionTask {
        return ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id,
            'production_stage_id' => $stage->id,
            'assigned_to'         => $assignee?->id,
            'sequence'            => $sequence,
            'status'              => $status,
            'quantity_done'       => $done,
            'started_at'          => in_array($status, ['in_progress', 'completed', 'paused']) ? now()->subHour() : null,
            'completed_at'        => $status === 'completed' ? now() : null,
        ]);
    }

    /** Cutting (A) → Stitching (B) → Finishing (C), each on a different bench. */
    private function line(int $quantity = 1): array
    {
        $po = $this->order($quantity);
        [$a, $b, $c] = [$this->tailor(), $this->tailor(), $this->tailor()];

        $cut    = $this->task($po, $this->stage('cut-f', 'Cutting', 1), $a, 1);
        $stitch = $this->task($po, $this->stage('stitch-f', 'Stitching', 2), $b, 2);
        $finish = $this->task($po, $this->stage('finish-f', 'Finishing', 3), $c, 3);

        return compact('po', 'a', 'b', 'c', 'cut', 'stitch', 'finish');
    }

    private function freshTask(ProductionTask $t): ProductionTask
    {
        return ProductionTask::withoutViewerScope()->findOrFail($t->id);
    }

    // ── B1: the gate reads the whole pipeline ───────────────────────────────

    public function test_an_earlier_stage_on_another_tailors_bench_blocks_her_start(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        $this->putJson("/api/v1/tailor/tasks/{$l['stitch']->id}/status", ['action' => 'start'])
            ->assertStatus(422)
            ->assertJsonPath('blocked_by.stage', 'Cutting');

        $this->assertSame('pending', $this->freshTask($l['stitch'])->status);
    }

    public function test_her_task_list_names_the_stage_she_is_waiting_on(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        $row = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json())
            ->firstWhere('id', $l['stitch']->id);

        $this->assertNotNull($row);
        $this->assertSame('Cutting', data_get($row, 'blocked_by_stage'));
    }

    public function test_she_cannot_count_more_pieces_than_the_earlier_bench_passed(): void
    {
        $l = $this->line(10);
        $l['cut']->update(['status' => 'in_progress', 'quantity_done' => 4, 'started_at' => now()]);
        Sanctum::actingAs($l['b']);

        $this->postJson("/api/v1/tailor/tasks/{$l['stitch']->id}/progress", ['quantity_done' => 5])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only 4 piece(s) have passed "Cutting" - you cannot record 5 here yet.']);

        $this->postJson("/api/v1/tailor/tasks/{$l['stitch']->id}/progress", ['quantity_done' => 4])
            ->assertOk();
        $this->assertSame(4, $this->freshTask($l['stitch'])->quantity_done);
    }

    public function test_the_earlier_bench_cannot_drop_below_what_she_already_took(): void
    {
        $l = $this->line(10);
        $l['cut']->update(['status' => 'in_progress', 'quantity_done' => 6, 'started_at' => now()]);
        $l['stitch']->update(['status' => 'in_progress', 'quantity_done' => 5, 'started_at' => now()]);
        Sanctum::actingAs($l['a']);

        $this->postJson("/api/v1/tailor/tasks/{$l['cut']->id}/progress", ['quantity_done' => 4])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '"Stitching" has already passed 5 piece(s) - this stage cannot drop below that.']);
    }

    public function test_finishing_her_own_stage_does_not_send_the_order_to_qc_while_others_are_open(): void
    {
        $l = $this->line();
        $l['cut']->update(['status' => 'completed', 'completed_at' => now(), 'started_at' => now()]);
        Sanctum::actingAs($l['b']);

        $this->putJson("/api/v1/tailor/tasks/{$l['stitch']->id}/status", ['action' => 'start'])->assertOk();
        $this->putJson("/api/v1/tailor/tasks/{$l['stitch']->id}/status", ['action' => 'complete'])->assertOk();

        $this->assertSame('completed', $this->freshTask($l['stitch'])->status);
        $this->assertSame('in_progress', $l['po']->fresh()->status,
            'Finishing is still open on another bench - the order is not ready for QC');
    }

    public function test_the_last_stage_on_any_bench_hands_the_order_to_qc(): void
    {
        $l = $this->line(2);
        $l['cut']->update(['status' => 'completed', 'quantity_done' => 2, 'completed_at' => now(), 'started_at' => now()]);
        $l['stitch']->update(['status' => 'completed', 'quantity_done' => 2, 'completed_at' => now(), 'started_at' => now()]);
        Sanctum::actingAs($l['c']);

        $this->postJson("/api/v1/tailor/tasks/{$l['finish']->id}/progress", ['quantity_done' => 2])->assertOk();

        $this->assertSame('completed', $this->freshTask($l['finish'])->status);
        $this->assertSame('qc_pending', $l['po']->fresh()->status);
    }

    public function test_reading_the_pipeline_does_not_widen_what_she_can_list(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        $ids = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($l['stitch']->id));
        $this->assertFalse($ids->contains($l['cut']->id), "the cutter's task is still not hers to see");
    }

    // ── B2: closed orders refuse floor work ─────────────────────────────────

    /** @return array<string, array{string}> */
    public static function closedStatuses(): array
    {
        return [
            'draft'      => ['draft'],
            'qc_pending' => ['qc_pending'],
            'qc_passed'  => ['qc_passed'],
            'qc_failed'  => ['qc_failed'],
            'completed'  => ['completed'],
            'cancelled'  => ['cancelled'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function openStatuses(): array
    {
        return [
            'pending'     => ['pending'],
            'in_progress' => ['in_progress'],
            'on_hold'     => ['on_hold'],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function test_a_tailor_cannot_change_counts_or_status_on_a_closed_order(string $status): void
    {
        $po    = $this->order(3, $status);
        $t     = $this->tailor();
        $task  = $this->task($po, $this->stage('stitch-c', 'Stitching', 1), $t, 1, 'completed', 3);
        Sanctum::actingAs($t);

        $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", ['quantity_done' => 2])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_CLOSED_FOR_FLOOR_WORK')
            ->assertJsonPath('order_status', $status);

        // A repeat of the final count used to flip a finished order back to QC.
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", ['quantity_done' => 3])
            ->assertStatus(422);

        foreach (['start', 'pause', 'complete'] as $action) {
            $this->putJson("/api/v1/tailor/tasks/{$task->id}/status", ['action' => $action])
                ->assertStatus(422)
                ->assertJsonPath('code', 'ORDER_CLOSED_FOR_FLOOR_WORK');
        }

        $this->postJson("/api/v1/tailor/tasks/{$task->id}/note", ['note' => 'late note'])
            ->assertStatus(422);

        $fresh = $this->freshTask($task);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(3, $fresh->quantity_done);
        $this->assertSame($status, $po->fresh()->status, 'the order status did not move');
    }

    #[DataProvider('openStatuses')]
    public function test_floor_work_is_accepted_while_the_order_is_open(string $status): void
    {
        $po   = $this->order(3, $status);
        $t    = $this->tailor();
        $task = $this->task($po, $this->stage('stitch-o', 'Stitching', 1), $t, 1);
        Sanctum::actingAs($t);

        $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", ['quantity_done' => 1])->assertOk();
        $this->assertSame(1, $this->freshTask($task)->quantity_done);
    }

    #[DataProvider('closedStatuses')]
    public function test_managers_are_refused_too(string $status): void
    {
        $po      = $this->order(1, $status);
        $t       = $this->tailor();
        $other   = $this->tailor();
        $stage   = $this->stage('stitch-m', 'Stitching', 1);
        $task    = $this->task($po, $stage, $t, 1, 'completed', 1);
        $pending = $this->task($po, $this->stage('press-m', 'Pressing', 2), null, 2);
        Sanctum::actingAs($this->manager());

        $closed = fn ($response) => $response->assertStatus(422)->assertJsonPath('code', 'ORDER_CLOSED_FOR_FLOOR_WORK');

        $closed($this->postJson("/api/v1/admin/production-orders/{$po->id}/assign", [
            'assignments' => [['task_id' => $task->id, 'tailor_id' => $other->id]],
        ]));
        $closed($this->putJson("/api/v1/admin/production-orders/{$po->id}/stage", [
            'task_id' => $task->id, 'action' => 'complete',
        ]));
        $closed($this->postJson("/api/v1/tailor/tasks/{$pending->id}/unlock", ['allow' => true]));
        $closed($this->postJson('/api/v1/admin/production-tasks', [
            'production_order_id' => $po->id, 'production_stage_id' => $stage->id,
        ]));
        $closed($this->putJson("/api/v1/admin/production-tasks/{$task->id}", ['assigned_to' => $other->id]));
        $closed($this->deleteJson("/api/v1/admin/production-tasks/{$pending->id}"));
        $closed($this->putJson("/api/v1/admin/production-tasks/{$task->id}/reassign", ['tailor_id' => $other->id]));

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'FAB-' . $po->id, 'name' => 'Wool', 'unit_of_measure' => 'm',
            'unit_cost' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $allocationId = DB::table('material_allocations')->insertGetId([
            'production_order_id' => $po->id, 'material_id' => $materialId,
            'quantity_required' => 2, 'quantity_allocated' => 0, 'quantity_used' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $closed($this->postJson("/api/v1/admin/production-orders/{$po->id}/materials", [
            'allocations' => [['allocation_id' => $allocationId, 'quantity' => 1]],
        ]));

        $fresh = $this->freshTask($task);
        $this->assertSame($t->id, $fresh->assigned_to);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull(ProductionTask::withoutViewerScope()->find($pending->id), 'nothing was deleted');
        $this->assertSame(1, ProductionTask::withoutViewerScope()->where('production_order_id', $po->id)->where('production_stage_id', $stage->id)->count());
        $this->assertSame($status, $po->fresh()->status);
    }

    public function test_finished_goods_enter_stock_once_however_often_complete_is_sent(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $po = ProductionOrder::create([
            'order_number' => 'PRD-T-DOUBLE',
            'product_id'   => $product->id,
            'status'       => 'qc_passed',
            'quantity'     => 3,
        ]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/admin/production-orders/{$po->id}/complete")->assertOk();
        $this->postJson("/api/v1/admin/production-orders/{$po->id}/complete")->assertStatus(422);

        $stockIns = DB::table('inventory_transactions')
            ->where('transaction_type', 'production')
            ->where('reference_type', ProductionOrder::class)
            ->where('reference_id', $po->id);
        $this->assertSame(1, $stockIns->count());
        $this->assertEquals(3, $stockIns->sum('quantity_change'));
        $this->assertSame('completed', $po->fresh()->status);
    }

    public function test_a_completed_order_cannot_be_walked_back_through_qc_to_stock_again(): void
    {
        // The B2 route to double stock: correction → re-count → QC → complete.
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $po = ProductionOrder::create([
            'order_number' => 'PRD-T-WALKBACK', 'product_id' => $product->id,
            'status' => 'qc_passed', 'quantity' => 2,
        ]);
        $t    = $this->tailor();
        $task = $this->task($po, $this->stage('stitch-w', 'Stitching', 1), $t, 1, 'completed', 2);

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/admin/production-orders/{$po->id}/complete")->assertOk();

        Sanctum::actingAs($t);
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", ['quantity_done' => 1])->assertStatus(422);
        $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", ['quantity_done' => 2])->assertStatus(422);

        $this->assertSame('completed', $po->fresh()->status);
        $this->assertSame(1, DB::table('inventory_transactions')
            ->where('reference_type', ProductionOrder::class)->where('reference_id', $po->id)->count());
    }

    // ── B10 / B-start ───────────────────────────────────────────────────────

    public function test_reassigning_keeps_the_task_state_and_count(): void
    {
        $l = $this->line(10);
        $l['cut']->update(['status' => 'in_progress', 'quantity_done' => 4, 'started_at' => now()]);
        $newCutter = $this->tailor();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/admin/production-orders/{$l['po']->id}/assign", [
            'assignments' => [['task_id' => $l['cut']->id, 'tailor_id' => $newCutter->id]],
        ])->assertOk();

        $fresh = $this->freshTask($l['cut']);
        $this->assertSame($newCutter->id, $fresh->assigned_to);
        $this->assertSame('in_progress', $fresh->status, 'it used to reset to pending');
        $this->assertSame(4, $fresh->quantity_done);
    }

    public function test_resuming_keeps_the_original_start_time(): void
    {
        $po    = $this->order(1);
        $t     = $this->tailor();
        $task  = $this->task($po, $this->stage('stitch-r', 'Stitching', 1), $t, 1, 'paused');
        $first = now()->subHours(3)->startOfSecond();
        $task->update(['started_at' => $first]);
        Sanctum::actingAs($t);

        $this->putJson("/api/v1/tailor/tasks/{$task->id}/status", ['action' => 'start'])->assertOk();

        $fresh = $this->freshTask($task);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($first), 'resume must not restart the clock');
    }

    // ── F1: notes go to the order chat ──────────────────────────────────────

    public function test_her_note_lands_in_the_orders_chat_thread(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        $res = $this->postJson("/api/v1/tailor/tasks/{$l['stitch']->id}/note", ['note' => 'Sleeve lining is short'])
            ->assertCreated();

        $channel = Channel::where('context_type', 'production_order')->where('context_id', $l['po']->id)->firstOrFail();
        $this->assertSame($channel->id, $res->json('channel_id'));

        $message = ChannelMessage::where('channel_id', $channel->id)->latest('id')->firstOrFail();
        $this->assertSame('📝 Stitching note: Sleeve lining is short', $message->body);
        $this->assertSame($l['b']->id, $message->user_id);
        $this->assertTrue($channel->members()->where('users.id', $l['b']->id)->exists());

        // And she can read it back through the ordinary chat route.
        $this->getJson("/api/v1/admin/channels/{$channel->id}/messages")
            ->assertOk()
            ->assertJsonFragment(['body' => '📝 Stitching note: Sleeve lining is short']);
    }

    public function test_she_cannot_post_a_note_on_someone_elses_task(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        // The cutter's task is outside her task scope, so it does not exist for her.
        $this->postJson("/api/v1/tailor/tasks/{$l['cut']->id}/note", ['note' => 'not mine'])
            ->assertNotFound();
        $this->assertSame(0, ChannelMessage::count());
    }

    // ── Order chat: open only to people who can see the order ───────────────

    public function test_a_tailor_cannot_open_the_thread_of_an_order_she_is_not_on(): void
    {
        $l        = $this->line();
        $outsider = $this->tailor();
        Sanctum::actingAs($outsider);

        $this->postJson('/api/v1/admin/channels/context', [
            'context_type' => 'production_order', 'context_id' => $l['po']->id,
        ])->assertNotFound();

        $this->assertSame(0, DB::table('channel_members')->where('user_id', $outsider->id)->count());
    }

    public function test_a_tailor_on_the_order_can_open_its_thread(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);

        $this->postJson('/api/v1/admin/channels/context', [
            'context_type' => 'production_order', 'context_id' => $l['po']->id,
        ])->assertOk();
    }

    public function test_a_tailor_moved_off_the_order_loses_its_thread(): void
    {
        $l = $this->line();
        Sanctum::actingAs($l['b']);
        $this->postJson("/api/v1/tailor/tasks/{$l['stitch']->id}/note", ['note' => 'On it'])->assertCreated();
        $channel = Channel::where('context_type', 'production_order')->where('context_id', $l['po']->id)->firstOrFail();

        Sanctum::actingAs($this->manager());
        $this->putJson("/api/v1/admin/production-tasks/{$l['stitch']->id}/reassign", ['tailor_id' => $l['c']->id])
            ->assertOk();

        $this->assertFalse($channel->members()->where('users.id', $l['b']->id)->exists(),
            'she was removed from the thread with the task');

        Sanctum::actingAs($l['b']);
        $this->getJson("/api/v1/admin/channels/{$channel->id}/messages")->assertForbidden();
    }

    public function test_a_stale_membership_does_not_outlive_visibility(): void
    {
        // Joined before this rule existed (or kept by any other path): the
        // thread still closes to anyone who cannot see the order.
        $l        = $this->line();
        $outsider = $this->tailor();
        $channel  = Channel::findOrCreateContext('production_order', $l['po']->id, 'PRD · test', $l['b']->id);
        $channel->members()->syncWithoutDetaching([$outsider->id => ['role' => 'member']]);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/admin/channels/{$channel->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/admin/channels/{$channel->id}/messages", ['body' => 'hello'])->assertForbidden();
    }

    public function test_a_stale_order_thread_drops_out_of_her_channel_list(): void
    {
        $l        = $this->line();
        $outsider = $this->tailor();
        $channel  = Channel::findOrCreateContext('production_order', $l['po']->id, 'PRD · test', $l['b']->id);
        $channel->members()->syncWithoutDetaching([
            $outsider->id => ['role' => 'member'],
            $l['b']->id   => ['role' => 'member'],
        ]);

        Sanctum::actingAs($outsider);
        $this->assertNotContains($channel->id,
            collect($this->getJson('/api/v1/admin/channels')->assertOk()->json('channels'))->pluck('id'),
            'the row carries the last message preview, so it must not be listed');

        Sanctum::actingAs($l['b']);
        $this->assertContains($channel->id,
            collect($this->getJson('/api/v1/admin/channels')->assertOk()->json('channels'))->pluck('id'));
    }

    public function test_nobody_can_be_added_to_an_order_thread_they_cannot_see(): void
    {
        $l        = $this->line();
        $outsider = $this->tailor();
        $manager  = $this->manager();
        $channel  = Channel::findOrCreateContext('production_order', $l['po']->id, 'PRD · test', $manager->id);
        $channel->members()->syncWithoutDetaching([$manager->id => ['role' => 'admin']]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/admin/channels/{$channel->id}/members", ['user_id' => $outsider->id])
            ->assertStatus(422);
        $this->postJson("/api/v1/admin/channels/{$channel->id}/members", ['user_id' => $l['c']->id])
            ->assertOk();
    }

    // ── read-only duplicate stock check ─────────────────────────────────────

    public function test_the_duplicate_stock_check_finds_a_double_stock_in_and_writes_nothing(): void
    {
        $po    = $this->order(2, 'completed');
        $clean = $this->order(1, 'completed');
        $itemId = DB::table('inventory_items')->insertGetId([
            'product_id' => $po->product_id,
            'outlet_id' => \App\Models\Outlet::factory()->create()->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $po->product_id])->id,
            'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'reorder_point' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$po->id, 2], [$po->id, 2], [$clean->id, 1]] as [$ref, $qty]) {
            DB::table('inventory_transactions')->insert([
                'inventory_item_id' => $itemId, 'transaction_type' => 'production',
                'reference_type' => ProductionOrder::class, 'reference_id' => $ref,
                'quantity_change' => $qty, 'quantity_before' => 0, 'quantity_after' => $qty,
                'created_at' => now(),
            ]);
        }
        $before = DB::table('inventory_transactions')->count();

        $this->artisan('production:check-duplicate-stock', ['--json' => true])->assertSuccessful();
        $this->assertSame($before, DB::table('inventory_transactions')->count(), 'read-only');

        Artisan::call('production:check-duplicate-stock', ['--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(1, $out['summary']['suspect_orders']);
        $this->assertSame($po->id, $out['findings'][0]['production_order_id']);
        $this->assertSame(2, $out['findings'][0]['stock_in_rows']);
        $this->assertEquals(2, $out['findings'][0]['excess_quantity']);
    }
}
