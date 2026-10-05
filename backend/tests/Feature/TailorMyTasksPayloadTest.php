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
 * What My Tasks receives (tailoring cycle 1): the stage's notes, its place in
 * the pipeline, and the WHOLE order's progress as numbers — never the other
 * benches' tasks or the people on them.
 */
class TailorMyTasksPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function tailor(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    public function test_her_task_carries_stage_notes_order_and_whole_order_progress(): void
    {
        $po = ProductionOrder::create([
            'order_number' => 'PRD-MT-1', 'product_id' => Product::factory()->create()->id,
            'status' => 'in_progress', 'quantity' => 10,
        ]);
        $cut    = ProductionStage::create(['name' => 'Cutting', 'slug' => 'cut-mt', 'sort_order' => 1, 'is_active' => true]);
        $stitch = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitch-mt', 'sort_order' => 2, 'is_active' => true,
            'description' => 'Double-stitch the yoke.']);
        $finish = ProductionStage::create(['name' => 'Finishing', 'slug' => 'finish-mt', 'sort_order' => 3, 'is_active' => true]);

        [$cutter, $stitcher, $finisher] = [$this->tailor(), $this->tailor(), $this->tailor()];
        $make = fn ($stage, $who, $seq, $status, $done) => ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id, 'production_stage_id' => $stage->id, 'assigned_to' => $who->id,
            'sequence' => $seq, 'status' => $status, 'quantity_done' => $done,
            'started_at' => $status === 'pending' ? null : now(),
        ]);
        // Cutting finished (10), Stitching 6 of 10, Finishing 2 of 10:
        // passed = 10 + 6 + 2 = 18 of 30 → 60%; 2 garments fully finished.
        $make($cut, $cutter, 1, 'completed', 10);
        $mine = $make($stitch, $stitcher, 2, 'in_progress', 6);
        $make($finish, $finisher, 3, 'in_progress', 2);

        Sanctum::actingAs($stitcher);
        $rows = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json());

        $this->assertSame([$mine->id], $rows->pluck('id')->all(), 'only her own stage is listed');
        $row = $rows->first();
        $this->assertSame('Double-stitch the yoke.', data_get($row, 'stage.description'));
        $this->assertSame(2, data_get($row, 'sequence'));
        $this->assertSame(['percent' => 60, 'finished' => 2, 'stages' => 3], data_get($row, 'production_order.progress'));

        // The progress is numbers only: no other bench's task, stage or person.
        $json = json_encode($row);
        $this->assertStringNotContainsString('Finishing', $json);
        $this->assertStringNotContainsString((string) $finisher->first_name, $json);
    }
}
