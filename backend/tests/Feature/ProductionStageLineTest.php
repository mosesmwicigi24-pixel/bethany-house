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
 * Production UI Cycle 2 (information hierarchy): the line that says where an
 * order is, and the calendar's open-work-by-stage panel.
 *
 * Before: an order with Cutting done and Stitching PAUSED read "Not started"
 * on the board (only in_progress / pending were looked for), and the panel
 * left paused stages out and labelled the rest "Stage 2".
 *
 * Fixture, written out:
 *   A  Cutting completed · Stitching paused   · Finishing pending → at Stitching
 *   B  Cutting pending (seq 1) · Stitching pending (seq 2), Stitching created
 *      first                                                      → at Cutting
 *   C  Cutting completed · Stitching completed                    → at nothing (null)
 *   Open work by stage: Cutting 1 (B) · Stitching 2 (A paused, B) · Finishing 1 (A)
 */
class ProductionStageLineTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    /** @var array<string, ProductionStage> */
    private array $stages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Cutting', 'Stitching', 'Finishing'] as $i => $name) {
            $this->stages[$name] = ProductionStage::create([
                'name' => $name, 'slug' => strtolower($name) . '-line', 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }

        $this->order('PRD-LINE-A', [['Cutting', 1, 'completed'], ['Stitching', 2, 'paused'], ['Finishing', 3, 'pending']]);
        $this->order('PRD-LINE-B', [['Stitching', 2, 'pending'], ['Cutting', 1, 'pending']]);
        $this->order('PRD-LINE-C', [['Cutting', 1, 'completed'], ['Stitching', 2, 'completed']]);
    }

    private function order(string $number, array $tasks): void
    {
        $order = ProductionOrder::create([
            'order_number' => $number, 'product_id' => Product::factory()->create()->id,
            'status' => 'in_progress', 'quantity' => 2, 'due_date' => now()->addDays(4)->toDateString(),
        ]);
        foreach ($tasks as [$stage, $seq, $status]) {
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $order->id, 'production_stage_id' => $this->stages[$stage]->id,
                'sequence' => $seq, 'status' => $status,
                'quantity_done' => $status === 'completed' ? 2 : 0,
            ]);
        }
    }

    public function test_the_board_names_the_stage_an_order_is_actually_at(): void
    {
        Sanctum::actingAs($this->manager);
        $rows = collect($this->getJson('/api/v1/admin/production-orders?per_page=50')->assertOk()->json('data'))
            ->keyBy('order_number');

        $this->assertSame('Stitching', $rows['PRD-LINE-A']['current_stage'], 'a paused stage is where the order is');
        $this->assertSame('Cutting', $rows['PRD-LINE-B']['current_stage'], 'earliest by sequence, not by insertion');
        $this->assertNull($rows['PRD-LINE-C']['current_stage'], 'every stage done → no current stage');

        $show = $this->getJson('/api/v1/admin/production-orders/' . ProductionOrder::where('order_number', 'PRD-LINE-A')->value('id'))
            ->assertOk()->json('order');
        $this->assertSame('Stitching', $show['current_stage'], 'the order page agrees with the list');
    }

    public function test_the_calendar_counts_paused_work_and_names_each_stage(): void
    {
        Sanctum::actingAs($this->manager);
        $body = $this->getJson('/api/v1/admin/production/schedule')->assertOk()->json();

        $byName = [];
        foreach ($body['by_stage'] as $stageId => $count) {
            $byName[$body['stage_names'][$stageId]] = $count;
        }
        ksort($byName);

        $this->assertSame(['Cutting' => 1, 'Finishing' => 1, 'Stitching' => 2], $byName);
    }
}
