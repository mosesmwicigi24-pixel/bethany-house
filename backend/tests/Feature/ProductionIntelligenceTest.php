<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3 — the floor, explained by its own data.
 *
 * The bottleneck metric reuses the piece-pipeline invariant exactly as the
 * progress endpoints enforce it (held at k = passed(k−1) − passed(k)), so a
 * number here can always be reproduced by hand from the tasks table.
 */
class ProductionIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('production_manager', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function stages(): array
    {
        return [
            ProductionStage::create(['name' => 'Cutting',   'slug' => 'pi-cut',    'sort_order' => 1, 'is_active' => true]),
            ProductionStage::create(['name' => 'Stitching', 'slug' => 'pi-stitch', 'sort_order' => 2, 'is_active' => true]),
            ProductionStage::create(['name' => 'Finishing', 'slug' => 'pi-finish', 'sort_order' => 3, 'is_active' => true]),
        ];
    }

    public function test_stage_cycle_times_average_the_bench_and_flag_overruns(): void
    {
        $this->viewer();
        [$cutting] = $this->stages();
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-PI-CT', 'product_id' => $product->id,
            'quantity' => 1, 'status' => 'in_progress',
        ]);

        $order2 = ProductionOrder::create([
            'order_number' => 'PRD-PI-CT2', 'product_id' => $product->id,
            'quantity' => 1, 'status' => 'in_progress',
        ]);

        // 5h actual vs 3h estimate → over; 2h actual vs 3h estimate → within.
        ProductionTask::create([
            'production_order_id' => $order->id, 'production_stage_id' => $cutting->id,
            'status' => 'completed', 'sequence' => 1, 'estimated_hours' => 3,
            'started_at' => now()->subHours(6), 'completed_at' => now()->subHour(),
        ]);
        ProductionTask::create([
            'production_order_id' => $order2->id, 'production_stage_id' => $cutting->id,
            'status' => 'completed', 'sequence' => 1, 'estimated_hours' => 3,
            'started_at' => now()->subHours(3), 'completed_at' => now()->subHour(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/production-intelligence?period=this_month')->assertOk();
        $cut = collect($res->json('cycle_times'))->firstWhere('stage', 'Cutting');

        $this->assertNotNull($cut);
        $this->assertSame(2, $cut['tasks']);
        // (5h + 2h) / 2 = 3.5h average against a 3h estimate; one task over.
        $this->assertSame(3.5, (float) $cut['avg_hours']);
        $this->assertSame(3.0, (float) $cut['avg_est_hours']);
        $this->assertSame(1, $cut['over_estimate']);
    }

    public function test_bottleneck_held_pieces_follow_the_pipeline_invariant(): void
    {
        $this->viewer();
        [$cutting, $stitching, $finishing] = $this->stages();
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-PI-BN', 'product_id' => $product->id,
            'quantity' => 20, 'status' => 'in_progress',
        ]);

        // Cutting done (20 passed) → stitching at 12 → finishing at 0.
        ProductionTask::create([
            'production_order_id' => $order->id, 'production_stage_id' => $cutting->id,
            'status' => 'completed', 'sequence' => 1, 'quantity_done' => 20,
        ]);
        ProductionTask::create([
            'production_order_id' => $order->id, 'production_stage_id' => $stitching->id,
            'status' => 'in_progress', 'sequence' => 2, 'quantity_done' => 12,
        ]);
        ProductionTask::create([
            'production_order_id' => $order->id, 'production_stage_id' => $finishing->id,
            'status' => 'pending', 'sequence' => 3, 'quantity_done' => 0,
        ]);

        $res = $this->getJson('/api/v1/admin/reports/production-intelligence')->assertOk();
        $held = collect($res->json('bottlenecks'))->keyBy('stage');

        // Held at stitching = 20 − 12 = 8; held at finishing = 12 − 0 = 12.
        $this->assertSame(8,  (int) $held['Stitching']['held_pieces']);
        $this->assertSame(12, (int) $held['Finishing']['held_pieces']);
        $this->assertArrayNotHasKey('Cutting', $held->all());

        // Finishing (12 held) outranks stitching (8): the list is worst-first.
        $this->assertSame('Finishing', $res->json('bottlenecks.0.stage'));
    }

    public function test_qc_rates_read_the_quality_checks_table(): void
    {
        $this->viewer();
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-PI-QC', 'product_id' => $product->id,
            'quantity' => 15, 'status' => 'qc_pending',
        ]);

        DB::table('production_quality_checks')->insert([
            ['production_order_id' => $order->id, 'passed' => true,  'passed_quantity' => 8, 'failed_quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['production_order_id' => $order->id, 'passed' => false, 'passed_quantity' => 0, 'failed_quantity' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = $this->getJson('/api/v1/admin/reports/production-intelligence')->assertOk();

        $this->assertSame(2, $res->json('qc.checks'));
        $this->assertSame(50.0, (float) $res->json('qc.pass_rate'));
        $this->assertSame(8, $res->json('qc.pieces_passed'));
        $this->assertSame(7, $res->json('qc.pieces_failed'));
    }

    public function test_capacity_outlook_compares_due_load_with_actual_pace(): void
    {
        $this->viewer();
        $product = Product::factory()->create();

        // 28 pieces completed in the last 14 days → 2/day → 14/week capacity.
        ProductionOrder::create([
            'order_number' => 'PRD-PI-DONE', 'product_id' => $product->id,
            'quantity' => 28, 'status' => 'completed', 'completed_at' => now()->subDays(3),
        ]);
        // 20 pieces due within 7 days → short by 6.
        ProductionOrder::create([
            'order_number' => 'PRD-PI-DUE', 'product_id' => $product->id,
            'quantity' => 20, 'status' => 'in_progress', 'due_date' => now()->addDays(4),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/production-intelligence')->assertOk();

        $this->assertSame(20, $res->json('capacity.due_pieces'));
        $this->assertSame(2.0, (float) $res->json('capacity.daily_throughput'));
        $this->assertSame(14.0, (float) $res->json('capacity.week_capacity'));
        $this->assertSame(6.0, (float) $res->json('capacity.shortfall'));

        // And the shortfall reaches the executive attention feed.
        $exec = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $cap = collect($exec->json('attention'))->firstWhere('key', 'capacity_shortfall');
        $this->assertNotNull($cap);
        $this->assertSame(6, $cap['count']);
    }

    public function test_tailor_throughput_counts_pieces_per_bench(): void
    {
        $this->viewer();
        [$cutting] = $this->stages();
        $tailor = User::factory()->create(['first_name' => 'Wanjiku', 'last_name' => 'Tailor']);
        $product = Product::factory()->create();
        $order = ProductionOrder::create([
            'order_number' => 'PRD-PI-TT', 'product_id' => $product->id,
            'quantity' => 10, 'status' => 'in_progress',
        ]);

        ProductionTask::create([
            'production_order_id' => $order->id, 'production_stage_id' => $cutting->id,
            'status' => 'completed', 'sequence' => 1, 'quantity_done' => 10,
            'assigned_to' => $tailor->id,
            'started_at' => now()->subHours(4), 'completed_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/production-intelligence')->assertOk();
        $row = collect($res->json('tailors'))->firstWhere('tailor', 'Wanjiku Tailor');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['tasks']);
        $this->assertSame(10, (int) $row['pieces']);
        $this->assertSame(4.0, (float) $row['avg_hours']);
    }

    public function test_reports_view_gates_the_endpoint(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/production-intelligence')->assertStatus(403);
    }
}
