<?php

namespace Tests\Feature;

use App\Models\PieceMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\Production\LedgerBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The mixed-write hazard, closed.
 *
 * Two write paths against one batch is the only thing in this system that can
 * corrupt live data silently: the old endpoint writes `quantity_done` with no
 * movement, the ledger goes stale, and the next real movement recomputes the
 * counter from that stale ledger and erases someone's afternoon.
 *
 * So the old endpoint stops writing counters on ledger-backed batches and
 * writes the movement it always described instead. These tests are the proof
 * that a tailor on the old screen and a tailor on the new one cannot disagree.
 */
class LegacyProgressTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function tailor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Cutting → Stitching → QC, backfilled onto the ledger and assigned. */
    private function seedOrder(int $quantity, array $passed, User $user): ProductionOrder
    {
        ProductionStage::create(['name' => 'Cutting',   'slug' => 'cutting-lt',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching-lt', 'sort_order' => 2, 'is_active' => true]);
        ProductionStage::create(['name' => 'QC',        'slug' => 'qc-lt',        'sort_order' => 3, 'is_active' => true]);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-LT-'.$product->id,
            'product_id'   => $product->id,
            'quantity'     => $quantity,
            'status'       => 'in_progress',
        ]);
        $order->seedTasks();
        $order->tasks()->update(['assigned_to' => $user->id]);

        foreach ($passed as $slug => $done) {
            $stage = ProductionStage::where('slug', $slug)->firstOrFail();
            ProductionTask::where('production_order_id', $order->id)
                ->where('production_stage_id', $stage->id)
                ->update(['quantity_done' => $done]);
        }

        app(LedgerBackfiller::class)->run();

        return $order->fresh('tasks');
    }

    private function taskAt(ProductionOrder $order, string $slug): ProductionTask
    {
        return ProductionTask::where('production_order_id', $order->id)
            ->whereHas('stage', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();
    }

    private function positions(ProductionOrder $order): array
    {
        return DB::table('v_batch_stage_distribution')
            ->where('production_order_id', $order->id)
            ->orderBy('seq')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->stage_name => (int) $r->qty_total])
            ->all();
    }

    private function record(ProductionTask $task, int $quantityDone)
    {
        return $this->postJson("/api/v1/tailor/tasks/{$task->id}/progress", [
            'quantity_done' => $quantityDone,
        ]);
    }

    // ------------------------------------------------------------------

    public function test_a_legacy_increment_becomes_exactly_one_forward_movement(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        $before = PieceMovement::count();

        $this->record($this->taskAt($order, 'cutting-lt'), 8)->assertOk();

        $forwards = PieceMovement::where('movement_type', 'FORWARD')->count()
            - PieceMovement::where('movement_type', 'FORWARD')->where('note', 'like', 'Migrated%')->count();

        $this->assertSame($before + 1, PieceMovement::count(), 'Exactly one movement should be written.');
        $this->assertGreaterThan(0, $forwards);

        // Two more pieces left Cutting for Stitching.
        $this->assertSame(
            ['Not Cut' => 0, 'Cutting' => 2, 'Stitching' => 6, 'QC' => 2, 'Finished' => 0, 'Scrapped' => 0],
            $this->positions($order),
        );
    }

    public function test_the_counter_still_reads_back_the_number_the_tailor_typed(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        $this->record($this->taskAt($order, 'cutting-lt'), 8)->assertOk();

        $this->assertSame(8, (int) $this->taskAt($order, 'cutting-lt')->fresh()->quantity_done);
    }

    /** The old client has no client_ref, so the ref is derived from the call. */
    public function test_replaying_the_same_legacy_call_moves_nothing_twice(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);
        $task  = $this->taskAt($order, 'cutting-lt');

        $this->record($task, 8)->assertOk();
        $after = PieceMovement::count();
        $positions = $this->positions($order);

        $this->record($task, 8)->assertOk();

        $this->assertSame($after, PieceMovement::count(), 'A retried legacy call must not move pieces again.');
        $this->assertSame($positions, $this->positions($order));
    }

    public function test_lowering_a_counter_is_refused_as_a_supervisor_reversal(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        $this->record($this->taskAt($order, 'cutting-lt'), 4)
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUIRES_REVERSAL');

        // Nothing moved.
        $this->assertSame(6, (int) $this->taskAt($order, 'cutting-lt')->fresh()->quantity_done);
    }

    public function test_recording_the_same_number_again_changes_nothing(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        $before = PieceMovement::count();
        $this->record($this->taskAt($order, 'cutting-lt'), 6)
            ->assertOk()
            ->assertJsonPath('moved', 0);

        $this->assertSame($before, PieceMovement::count());
    }

    /**
     * "Five passed cutting" also asserts five started it. On an order nobody has
     * opened, those five are still in the entry queue — the translator brings
     * them down rather than failing on an empty bench.
     */
    public function test_progress_on_an_untouched_order_pulls_the_pieces_out_of_the_queue(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, [], $user);
        $order->update(['status' => 'pending', 'started_at' => null]);

        $this->assertSame(10, $this->positions($order)['Not Cut']);

        $this->record($this->taskAt($order, 'cutting-lt'), 5)->assertOk();

        $this->assertSame(
            ['Not Cut' => 5, 'Cutting' => 0, 'Stitching' => 5, 'QC' => 0, 'Finished' => 0, 'Scrapped' => 0],
            $this->positions($order),
        );
        $this->assertSame(5, (int) $this->taskAt($order, 'cutting-lt')->fresh()->quantity_done);
    }

    public function test_a_legacy_write_can_never_exceed_what_the_line_holds(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        // 11 of a 10-piece batch: refused by the batch cap before it reaches the ledger.
        $this->record($this->taskAt($order, 'cutting-lt'), 11)->assertStatus(422);

        $this->assertSame(0, DB::table('v_batch_integrity')->where('is_balanced', false)->count());
    }

    public function test_conservation_holds_across_a_legacy_write(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6, 'stitching-lt' => 2], $user);

        $this->record($this->taskAt($order, 'cutting-lt'), 9)->assertOk();
        $this->record($this->taskAt($order, 'stitching-lt'), 7)->assertOk();

        $this->assertSame(0, DB::table('v_batch_integrity')->where('is_balanced', false)->count());
        $this->assertSame(10, array_sum($this->positions($order)));
    }

    /**
     * The backfill gives batch-less orders a batch. An old client that has never
     * sent a batch_id must not suddenly be asked to choose a colourway.
     */
    public function test_an_order_with_a_single_batch_needs_no_batch_id(): void
    {
        $user  = $this->tailor();
        $order = $this->seedOrder(10, ['cutting-lt' => 6], $user);

        $this->assertSame(1, ProductionOrderBatch::where('production_order_id', $order->id)->count());

        $this->record($this->taskAt($order, 'cutting-lt'), 7)->assertOk();
    }
}
