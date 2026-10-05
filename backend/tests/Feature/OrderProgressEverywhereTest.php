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
 * Production UI Cycle 1 (coherence): one order, one progress figure — on the
 * order page, the orders list, the calendar's schedule feed and My Tasks, for
 * a manager and for a tailor who holds only one of its stages.
 *
 * Before: My Tasks 53%, the order page "33% complete" (finished stages ÷
 * stages), a tailor's order page 0% (her own stage only).
 *
 * Fixture, written out: 10 cassocks, three stages —
 *   Cutting   completed      → passes 10
 *   Stitching in progress, 6 → passes 6
 *   Finishing pending, 0     → passes 0
 *   percent  = (10 + 6 + 0) / (10 × 3) = 53.3 → 53
 *   finished = pieces through Finishing = 0
 */
class OrderProgressEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['percent' => 53, 'finished' => 0, 'stages' => 3];

    private User $mary;
    private User $manager;
    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        $this->mary    = $this->user('tailor');
        $john          = $this->user('tailor');
        $this->manager = $this->user('admin');

        $this->order = ProductionOrder::create([
            'order_number' => 'PRD-PROG-1', 'product_id' => Product::factory()->create()->id,
            'status' => 'in_progress', 'quantity' => 10, 'due_date' => now()->addDays(3)->toDateString(),
        ]);
        foreach ([['Cutting', $john, 'completed', 10], ['Stitching', $this->mary, 'in_progress', 6], ['Finishing', $john, 'pending', 0]] as $i => [$name, $who, $status, $done]) {
            $stage = ProductionStage::create(['name' => $name, 'slug' => strtolower($name) . '-prog', 'sort_order' => $i + 1, 'is_active' => true]);
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $this->order->id, 'production_stage_id' => $stage->id,
                'assigned_to' => $who->id, 'sequence' => $i + 1, 'status' => $status, 'quantity_done' => $done,
            ]);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function assertProgress(array $payload, string $where): void
    {
        $this->assertSame(self::EXPECTED, $payload['progress'] ?? null, "{$where}: progress object");
        $this->assertSame(53, $payload['completion_percentage'] ?? null, "{$where}: completion_percentage");
    }

    public function test_the_manager_sees_one_figure_on_every_surface(): void
    {
        Sanctum::actingAs($this->manager);

        $this->assertProgress($this->getJson("/api/v1/admin/production-orders/{$this->order->id}")->assertOk()->json('order'), 'order page');
        $this->assertProgress($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data.0'), 'orders list');

        $row = collect($this->getJson('/api/v1/admin/production/schedule')->assertOk()->json('upcoming_orders'))
            ->firstWhere('order_number', 'PRD-PROG-1');
        $this->assertSame(53, $row['completion_percentage'], 'calendar schedule feed');

        $this->assertSame(53, $this->order->fresh()->getCompletionPercentage(), 'model helper agrees');
    }

    public function test_a_tailor_holding_one_stage_sees_the_whole_orders_figure(): void
    {
        Sanctum::actingAs($this->mary);

        $this->assertProgress($this->getJson("/api/v1/admin/production-orders/{$this->order->id}")->assertOk()->json('order'), 'tailor order page');
        $this->assertProgress($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data.0'), 'tailor orders list');

        $task = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json())->first();
        $this->assertSame(self::EXPECTED, $task['production_order']['progress'], 'My Tasks');

        // Only her own stage is in the order payload — numbers, not other benches.
        $stages = $this->getJson("/api/v1/admin/production-orders/{$this->order->id}")->json('order.tasks');
        $this->assertCount(1, $stages);
    }

    public function test_an_order_without_stages_reads_zero_not_an_error(): void
    {
        $empty = ProductionOrder::create([
            'order_number' => 'PRD-PROG-EMPTY', 'product_id' => Product::factory()->create()->id,
            'status' => 'draft', 'quantity' => 2,
        ]);

        Sanctum::actingAs($this->manager);
        $order = $this->getJson("/api/v1/admin/production-orders/{$empty->id}")->assertOk()->json('order');

        $this->assertNull($order['progress']);
        $this->assertSame(0, $order['completion_percentage']);
    }

    public function test_the_qc_tiles_count_every_outcome_whatever_tab_is_open(): void
    {
        foreach (['qc_pending', 'qc_passed', 'qc_passed', 'qc_failed'] as $i => $status) {
            ProductionOrder::create([
                'order_number' => "PRD-QC-{$i}", 'product_id' => Product::factory()->create()->id,
                'status' => $status, 'quantity' => 1,
            ]);
        }

        Sanctum::actingAs($this->manager);
        // The QC screen's "Awaiting" tab asks for qc_pending rows only…
        $stats = $this->getJson('/api/v1/admin/production-orders?status=qc_pending')->assertOk()->json('stats');

        // …and its tiles still read every outcome.
        $this->assertSame(1, $stats['qc_pending']);
        $this->assertSame(2, $stats['qc_passed']);
        $this->assertSame(1, $stats['qc_failed']);
    }
}
