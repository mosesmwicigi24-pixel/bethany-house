<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
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
 * Production UI Cycle 4 (interaction quality): My Tasks' checklist read
 * "Your stages 0/2 done" after she finished one, because the Active list
 * held only open tasks. With include_order_context it now carries her
 * finished stages on orders still in motion — without reordering the queue.
 *
 * Fixture, written out (risk score = days + priority + est. hours + status):
 *   Order A, due in 10d, normal: Cutting COMPLETED (est 20h), Stitching IN PROGRESS (est 4h)
 *     open score 12 + 5 + 4 − 5 = 16; the finished stage alone would score 37
 *   Order B, due in 10d, normal: Cutting PENDING (est 4h) → 12 + 5 + 4 = 21
 *   Order C, cancelled: Cutting COMPLETED
 *   Home ranks B before A; Focus must too.
 */
class MyTasksOrderContextTest extends TestCase
{
    use RefreshDatabase;

    private User $mary;
    private ProductionOrder $a;
    private ProductionOrder $b;
    private ProductionOrder $c;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->mary = User::factory()->create();
        $this->mary->assignRole(Role::findByName('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->mary = $this->mary->fresh();

        $cut    = ProductionStage::create(['name' => 'Cutting',   'slug' => 'ctx-cut',    'sort_order' => 1, 'is_active' => true]);
        $stitch = ProductionStage::create(['name' => 'Stitching', 'slug' => 'ctx-stitch', 'sort_order' => 2, 'is_active' => true]);

        $this->a = $this->order('PRD-CTX-A', 'in_progress', [[$cut, 1, 'completed', 20], [$stitch, 2, 'in_progress', 4]]);
        $this->b = $this->order('PRD-CTX-B', 'pending',     [[$cut, 1, 'pending', 4]]);
        $this->c = $this->order('PRD-CTX-C', 'cancelled',   [[$cut, 1, 'completed', 4]]);
    }

    private function order(string $number, string $status, array $tasks): ProductionOrder
    {
        $o = ProductionOrder::create([
            'order_number' => $number, 'product_id' => Product::factory()->create()->id,
            'status' => $status, 'quantity' => 1, 'priority' => 'normal',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        foreach ($tasks as [$stage, $seq, $taskStatus, $hours]) {
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $o->id, 'production_stage_id' => $stage->id,
                'assigned_to' => $this->mary->id, 'sequence' => $seq, 'status' => $taskStatus,
                'estimated_hours' => $hours, 'quantity_done' => $taskStatus === 'completed' ? 1 : 0,
            ]);
        }

        return $o;
    }

    /** @return list<int> order ids in first-seen order */
    private function orderSequence(array $tasks): array
    {
        return array_values(array_unique(array_column($tasks, 'production_order_id')));
    }

    public function test_her_finished_stage_comes_with_the_order_so_the_checklist_can_count(): void
    {
        Sanctum::actingAs($this->mary);
        $tasks = collect($this->getJson('/api/v1/tailor/tasks?include_order_context=true')->assertOk()->json());

        $onA = $tasks->where('production_order_id', $this->a->id);
        $this->assertSame(['completed', 'in_progress'], $onA->pluck('status')->sort()->values()->all(), 'both her stages on A: 1 of 2 done');
        $this->assertNull($tasks->firstWhere('production_order_id', $this->c->id), 'a cancelled order is not context');
    }

    public function test_home_list_stays_open_work_only(): void
    {
        Sanctum::actingAs($this->mary);
        $home = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json());

        $this->assertSame(['in_progress', 'pending'], $home->pluck('status')->sort()->values()->all());
    }

    public function test_finished_stages_do_not_reorder_the_queue(): void
    {
        Sanctum::actingAs($this->mary);
        $home  = $this->getJson('/api/v1/tailor/tasks')->assertOk()->json();
        $focus = $this->getJson('/api/v1/tailor/tasks?include_order_context=true')->assertOk()->json();

        $this->assertSame([$this->b->id, $this->a->id], $this->orderSequence($home), 'Home: B (21) before A (16)');
        $this->assertSame($this->orderSequence($home), $this->orderSequence($focus), "Focus ranks the same — A's finished 20h stage does not lift it");
    }

    public function test_the_cycle_3_flag_name_is_still_accepted(): void
    {
        Sanctum::actingAs($this->mary);
        $tasks = collect($this->getJson('/api/v1/tailor/tasks?include_awaiting_qc=true')->assertOk()->json());

        $this->assertCount(2, $tasks->where('production_order_id', $this->a->id), 'a cached console still gets the context');
    }
}
