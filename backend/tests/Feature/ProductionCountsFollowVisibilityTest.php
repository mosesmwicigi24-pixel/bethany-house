<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tailor View Cycle 2 (F5/B26): every production count a person sees is
 * counted over the orders they may see, ProductionOrder::visibleTo, the rule
 * the Production Orders list already uses. Before, the Home tiles, the
 * overdue banner and the list tiles were shop-wide, so a tailor read
 * "3 active, 2 overdue" beside her own list of 2 and 1.
 *
 * The expected figures are written out from the fixture below, not
 * recomputed with the code's own query.
 */
class ProductionCountsFollowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shopA;
    private Outlet $shopB;
    private User $mary;
    private User $john;
    private ProductionStage $stage;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');

        // Test-only shim, rolled back with the test's transaction. The
        // dashboard's low-stock count reads inventories.quantity and
        // .low_stock_threshold, which do not exist (the table has
        // quantity_on_hand / reorder_point) — a separate, reported defect. In
        // production each query commits on its own, so that failure only
        // loses the low-stock figure; inside a test's transaction Postgres
        // aborts every later query of the request, the production counts
        // included. Giving the stray query its columns lets this request run
        // the way it does in production.
        Schema::table('inventories', function (Blueprint $t) {
            $t->integer('quantity')->nullable();
            $t->integer('low_stock_threshold')->nullable();
        });

        $this->shopA = Outlet::factory()->create(['name' => 'Shop A']);
        $this->shopB = Outlet::factory()->create(['name' => 'Shop B']);
        $this->mary  = $this->user('tailor');
        $this->john  = $this->user('tailor');
        $this->stage = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitch-cnt', 'sort_order' => 1, 'is_active' => true]);

        // Fixture (today = now):
        //   A  in_progress  Shop A  Mary   due +2
        //   B  pending      Shop A  Mary   due +6
        //   C  in_progress  Shop B  Mary   due -3  (overdue)
        //   E  in_progress  Shop B  John   due -5  (overdue)
        //   F  pending      Shop A  John   due +3
        //   G  draft        Shop A  —      no tasks
        $this->order('A', 'in_progress', $this->shopA, $this->mary, 2);
        $this->order('B', 'pending',     $this->shopA, $this->mary, 6);
        $this->order('C', 'in_progress', $this->shopB, $this->mary, -3);
        $this->order('E', 'in_progress', $this->shopB, $this->john, -5);
        $this->order('F', 'pending',     $this->shopA, $this->john, 3);
        $this->order('G', 'draft',       $this->shopA, null, 10);
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) $u->outlets()->attach($outlet->id, ['is_primary' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function order(string $tag, string $status, Outlet $shop, ?User $tailor, int $dueInDays): ProductionOrder
    {
        $po = ProductionOrder::create([
            'order_number' => "PRD-CNT-{$tag}", 'product_id' => Product::factory()->create()->id,
            'quantity' => 2, 'status' => $status, 'outlet_id' => $shop->id,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
        ]);
        if ($tailor) {
            ProductionTask::withoutViewerScope()->create([
                'production_order_id' => $po->id, 'production_stage_id' => $this->stage->id,
                'assigned_to' => $tailor->id, 'sequence' => 1,
                'status' => $status === 'in_progress' ? 'in_progress' : 'pending', 'quantity_done' => 0,
            ]);
        }

        return $po;
    }

    private function dashboardStats(User $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
    }

    private function overdueBanner(User $u): ?string
    {
        Sanctum::actingAs($u);
        $alerts = collect($this->getJson('/api/v1/admin/dashboard')->assertOk()->json('alerts'));

        return $alerts->firstWhere('icon', 'production')['message'] ?? null;
    }

    private function listStats(User $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api/v1/admin/production-orders')->assertOk()->json('stats');
    }

    public function test_a_tailors_home_tiles_count_only_her_orders(): void
    {
        $s = $this->dashboardStats($this->mary);

        $this->assertSame(2, $s['production_in_progress'], 'A and C');
        $this->assertSame(1, $s['production_queue'], 'B');
        $this->assertSame(1, $s['production_overdue'], 'C only, not John\'s E');
        $this->assertSame(0, $s['production_draft']);
    }

    public function test_the_overdue_banner_and_the_overdue_tile_agree(): void
    {
        $this->assertSame('1 production order is overdue', $this->overdueBanner($this->mary));
        $this->assertSame(1, $this->dashboardStats($this->mary)['production_overdue']);
    }

    public function test_the_production_orders_tiles_match_the_list_beside_them(): void
    {
        Sanctum::actingAs($this->mary);
        $rows = collect($this->getJson('/api/v1/admin/production-orders')->assertOk()->json('data'));
        $s = $this->listStats($this->mary);

        $this->assertEqualsCanonicalizing(['PRD-CNT-A', 'PRD-CNT-B', 'PRD-CNT-C'], $rows->pluck('order_number')->all());
        $this->assertSame(['draft' => 0, 'pending' => 1, 'in_progress' => 2, 'qc_pending' => 0, 'qc_passed' => 0, 'qc_failed' => 0, 'completed' => 0, 'overdue' => 1], $s);
    }

    public function test_someone_who_runs_the_floor_still_sees_the_whole_shop(): void
    {
        $admin = $this->user('admin');

        $s = $this->dashboardStats($admin);
        $this->assertSame(3, $s['production_in_progress'], 'A, C, E');
        $this->assertSame(2, $s['production_queue'], 'B, F');
        $this->assertSame(2, $s['production_overdue'], 'C, E');
        $this->assertSame(1, $s['production_draft'], 'G');

        $this->assertSame(['draft' => 1, 'pending' => 2, 'in_progress' => 3, 'qc_pending' => 0, 'qc_passed' => 0, 'qc_failed' => 0, 'completed' => 0, 'overdue' => 2], $this->listStats($admin));
        $this->assertSame('2 production orders are overdue', $this->overdueBanner($admin));
    }

    public function test_an_outlet_managers_tiles_follow_their_outlets(): void
    {
        // Owner decision 2026-10-05: outlet managers' counts follow their
        // outlets, as their order list already does.
        $manager = $this->user('outlet_manager', $this->shopA);

        $s = $this->dashboardStats($manager);
        $this->assertSame(1, $s['production_in_progress'], 'A (C and E are Shop B)');
        $this->assertSame(2, $s['production_queue'], 'B, F');
        $this->assertSame(0, $s['production_overdue'], 'both overdue orders are Shop B');
        $this->assertSame(1, $s['production_draft'], 'G');

        $this->assertSame(['draft' => 1, 'pending' => 2, 'in_progress' => 1, 'qc_pending' => 0, 'qc_passed' => 0, 'qc_failed' => 0, 'completed' => 0, 'overdue' => 0], $this->listStats($manager));
    }

    public function test_open_tasks_on_a_cancelled_order_leave_my_tasks(): void
    {
        $po = ProductionOrder::where('order_number', 'PRD-CNT-B')->first();
        $finished = ProductionStage::create(['name' => 'Cutting', 'slug' => 'cut-cnt', 'sort_order' => 0, 'is_active' => true]);
        $done = ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id, 'production_stage_id' => $finished->id,
            'assigned_to' => $this->mary->id, 'sequence' => 0, 'status' => 'completed', 'quantity_done' => 2,
        ]);
        $po->update(['status' => 'cancelled']);

        Sanctum::actingAs($this->mary);
        $active = collect($this->getJson('/api/v1/tailor/tasks')->assertOk()->json())
            ->pluck('production_order.order_number')->unique()->sort()->values()->all();
        $this->assertSame(['PRD-CNT-A', 'PRD-CNT-C'], $active, 'the cancelled order\'s pending stage is gone');

        $history = collect($this->getJson('/api/v1/tailor/tasks?include_completed=1')->assertOk()->json());
        $this->assertTrue($history->contains('id', $done->id), 'finished work on it stays in her history');
        $this->assertFalse(
            $history->contains(fn ($t) => $t['production_order']['order_number'] === 'PRD-CNT-B' && $t['status'] === 'pending'),
            'but not its open stage'
        );
    }
}
