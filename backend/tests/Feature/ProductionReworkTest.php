<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
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
 * The way out of a failed QC (audit ST-2), as the owner set it on 7 Oct 2026:
 * the QC manager chooses the stage the order goes back to, how many pieces it
 * redoes, and who redoes it (the same tailor or another).
 *
 * Fixture, written out: 10 cassocks, failed QC (2 failed, 8 passed).
 *   Cutting  — cutter,   completed 10
 *   Buttons  — stitcher, completed 10
 *   Pressing — presser,  completed 10
 * Send back: Buttons, 2 pieces, to a new tailor, "Loose buttons on two".
 * Expected: Buttons pending at 8/10 for the new tailor; Cutting and Pressing
 * untouched; order in progress; the QC record kept. When the new tailor
 * counts Buttons back to 10, the order returns to QC by itself.
 */
class ProductionReworkTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $cutter;
    private User $stitcher;
    private User $presser;
    private User $newTailor;
    private ProductionOrder $order;
    /** @var array<string, ProductionTask> */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->manager   = $this->user('admin');
        $this->cutter    = $this->user('tailor');
        $this->stitcher  = $this->user('tailor');
        $this->presser   = $this->user('tailor');
        $this->newTailor = $this->user('tailor');

        $this->order = $this->failedOrder('PRD-RWK-1');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function failedOrder(string $number): ProductionOrder
    {
        $o = ProductionOrder::create([
            'order_number' => $number, 'product_id' => Product::factory()->create()->id,
            'status' => 'qc_failed', 'quantity' => 10, 'due_date' => now()->addDays(5)->toDateString(),
        ]);
        foreach ([['Cutting', $this->cutter], ['Buttons', $this->stitcher], ['Pressing', $this->presser]] as $i => [$name, $who]) {
            $stage = ProductionStage::firstOrCreate(['slug' => strtolower($name) . '-rwk'], ['name' => $name, 'sort_order' => $i + 1, 'is_active' => true]);
            $this->tasks[$name] = ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $o->id, 'production_stage_id' => $stage->id, 'assigned_to' => $who->id,
                'sequence' => $i + 1, 'status' => 'completed', 'quantity_done' => 10, 'completed_at' => now(),
            ]);
        }
        DB::table('production_quality_checks')->insert([
            'production_order_id' => $o->id, 'passed' => false, 'passed_quantity' => 8, 'failed_quantity' => 2,
            'defect_types' => '[]', 'notes' => 'Loose buttons', 'checked_by' => $this->manager->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $o;
    }

    private function sendBack(array $payload)
    {
        return $this->postJson("/api/v1/admin/production-orders/{$this->order->id}/rework", $payload);
    }

    private function task(string $name): ProductionTask
    {
        return ProductionTask::withoutViewerScope()->find($this->tasks[$name]->id);
    }

    public function test_the_qc_manager_sends_one_stage_back_for_some_pieces_to_a_new_tailor(): void
    {
        Sanctum::actingAs($this->manager);
        $this->sendBack([
            'reason' => 'Loose buttons on two',
            'stages' => [['task_id' => $this->tasks['Buttons']->id, 'pieces' => 2, 'assigned_to' => $this->newTailor->id]],
        ])->assertOk();

        $this->assertSame('in_progress', $this->order->fresh()->status);

        $buttons = $this->task('Buttons');
        $this->assertSame('pending', $buttons->status);
        $this->assertSame(8, (int) $buttons->quantity_done, '10 done − 2 to redo');
        $this->assertSame($this->newTailor->id, (int) $buttons->assigned_to);
        $this->assertNull($buttons->completed_at);

        foreach (['Cutting', 'Pressing'] as $untouched) {
            $t = $this->task($untouched);
            $this->assertSame(['completed', 10], [$t->status, (int) $t->quantity_done], "{$untouched} is not reopened");
        }

        $this->assertSame(1, DB::table('production_quality_checks')->where('production_order_id', $this->order->id)->count(), 'the failed QC stays as history');

        // The new tailor is told what, how many and why; the old one is not.
        $notes = fn (User $u) => DB::table('notifications')->where('notifiable_id', $u->id)->pluck('data')
            ->map(fn ($d) => json_decode($d, true))->filter(fn ($d) => str_starts_with($d['title'] ?? '', 'Rework:'))->values();
        $mine = $notes($this->newTailor);
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('Buttons (2 pieces)', $mine[0]['body']);
        $this->assertStringContainsString('Loose buttons on two', $mine[0]['body']);
        $this->assertCount(0, $notes($this->stitcher));

        // It is open work on her My Tasks.
        Sanctum::actingAs($this->newTailor);
        $list = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json());
        $this->assertSame([$this->tasks['Buttons']->id], $list->pluck('id')->all());
    }

    public function test_the_same_tailor_can_redo_it_and_the_order_returns_to_qc_when_done(): void
    {
        Sanctum::actingAs($this->manager);
        $this->sendBack([
            'reason' => 'Loose buttons on two',
            'stages' => [['task_id' => $this->tasks['Buttons']->id, 'pieces' => 2]],
        ])->assertOk();
        $this->assertSame($this->stitcher->id, (int) $this->task('Buttons')->assigned_to, 'no assignee given → the same tailor');

        Sanctum::actingAs($this->stitcher);
        $this->postJson("/api/v1/tailor/tasks/{$this->tasks['Buttons']->id}/progress", ['quantity_done' => 10])->assertOk();

        $this->assertSame('completed', $this->task('Buttons')->status);
        $this->assertSame('qc_pending', $this->order->fresh()->status, 'back to QC through the normal hand-off');
    }

    public function test_without_pieces_the_whole_stage_is_redone(): void
    {
        Sanctum::actingAs($this->manager);
        $this->sendBack(['reason' => 'Wrong buttons', 'stages' => [['task_id' => $this->tasks['Buttons']->id]]])->assertOk();

        $this->assertSame(0, (int) $this->task('Buttons')->quantity_done);
    }

    public function test_refusals(): void
    {
        $buttons = ['task_id' => $this->tasks['Buttons']->id, 'pieces' => 2];

        Sanctum::actingAs($this->stitcher);
        $this->sendBack(['reason' => 'x', 'stages' => [$buttons]])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->sendBack(['reason' => 'x', 'stages' => [['task_id' => $this->tasks['Buttons']->id, 'pieces' => 11]]])->assertStatus(422);

        $mine = $this->tasks;               // failedOrder() below refills $this->tasks
        $other = $this->failedOrder('PRD-RWK-2');
        $this->tasks = $mine;
        $otherTask = ProductionTask::withoutViewerScope()->where('production_order_id', $other->id)->value('id');
        $this->sendBack(['reason' => 'x', 'stages' => [['task_id' => $otherTask]]])->assertStatus(422);

        $this->sendBack(['reason' => 'x', 'stages' => [$buttons]])->assertOk();
        $this->sendBack(['reason' => 'again', 'stages' => [$buttons]])->assertStatus(422);
        $this->assertSame(8, (int) $this->task('Buttons')->quantity_done, 'a second send-back changes nothing');
    }
}
