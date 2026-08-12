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
use Tests\TestCase;

/**
 * Migrating a live floor onto the ledger.
 *
 * The property that matters is losslessness: the positions the backfill derives
 * must re-derive the exact counters it started from. If they do, no screen, no
 * gate and no report changes the day this ships.
 */
class LedgerBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $quantity, array $passed): ProductionOrder
    {
        User::factory()->create();

        ProductionStage::create(['name' => 'Cutting',   'slug' => 'cutting-bf',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching-bf', 'sort_order' => 2, 'is_active' => true]);
        ProductionStage::create(['name' => 'QC',        'slug' => 'qc-bf',        'sort_order' => 3, 'is_active' => true]);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-BF-'.$product->id,
            'product_id'   => $product->id,
            'quantity'     => $quantity,
            'status'       => 'in_progress',
        ]);
        $order->seedTasks();

        foreach ($passed as $slug => $done) {
            $stage = ProductionStage::where('slug', $slug)->firstOrFail();
            ProductionTask::where('production_order_id', $order->id)
                ->where('production_stage_id', $stage->id)
                ->update(['quantity_done' => $done]);
        }

        return $order->fresh('tasks');
    }

    /** Pieces standing at each stage, keyed by stage name. */
    private function positions(ProductionOrder $order): array
    {
        return DB::table('v_batch_stage_distribution')
            ->where('production_order_id', $order->id)
            ->orderBy('seq')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->stage_name => (int) $r->qty_total])
            ->all();
    }

    /** Re-derive passed() from positions: everything standing downstream. */
    private function derivedPassed(ProductionOrder $order, string $stageName): int
    {
        $rows = DB::table('v_batch_stage_distribution')
            ->where('production_order_id', $order->id)
            ->where('kind', '!=', 'SCRAP')
            ->orderBy('seq')
            ->get();

        $seen = false;
        $sum  = 0;

        foreach ($rows as $row) {
            if ($seen) {
                $sum += (int) $row->qty_total;
            }
            if ($row->stage_name === $stageName) {
                $seen = true;
            }
        }

        return $sum;
    }

    public function test_counters_become_positions_that_re_derive_the_same_counters(): void
    {
        $order = $this->order(10, ['cutting-bf' => 6, 'stitching-bf' => 2, 'qc-bf' => 0]);

        app(LedgerBackfiller::class)->run();

        // Work has started, so every piece has left the queue. Six were cut but
        // only two stitched, so four stand at Stitching and those two at QC —
        // and the four not yet cut stand at Cutting, which is where the tailor
        // holding them will look for them.
        $this->assertSame(
            ['Not Cut' => 0, 'Cutting' => 4, 'Stitching' => 4, 'QC' => 2, 'Finished' => 0, 'Scrapped' => 0],
            $this->positions($order),
        );

        // Lossless: the numbers the floor was running on come back unchanged.
        $this->assertSame(6, $this->derivedPassed($order, 'Cutting'));
        $this->assertSame(2, $this->derivedPassed($order, 'Stitching'));
        $this->assertSame(0, $this->derivedPassed($order, 'QC'));
    }

    /**
     * The other half of the ambiguity: an order nobody has opened must not be
     * reported as cutting in progress.
     */
    public function test_an_untouched_order_keeps_its_pieces_in_the_queue(): void
    {
        $order = $this->order(10, []);
        $order->update(['status' => 'pending', 'started_at' => null]);

        app(LedgerBackfiller::class)->run();

        $this->assertSame(
            ['Not Cut' => 10, 'Cutting' => 0, 'Stitching' => 0, 'QC' => 0, 'Finished' => 0, 'Scrapped' => 0],
            $this->positions($order),
        );
    }

    public function test_the_cutover_diff_is_empty_after_a_clean_backfill(): void
    {
        $this->order(10, ['cutting-bf' => 6, 'stitching-bf' => 2, 'qc-bf' => 0]);

        $backfiller = app(LedgerBackfiller::class);
        $backfiller->run();

        $this->assertSame([], $backfiller->diff());
    }

    public function test_every_backfilled_batch_balances(): void
    {
        $this->order(10, ['cutting-bf' => 6, 'stitching-bf' => 2, 'qc-bf' => 0]);

        app(LedgerBackfiller::class)->run();

        $this->assertSame(0, DB::table('v_batch_integrity')->where('is_balanced', false)->count());
    }

    public function test_an_order_with_no_batches_gets_one_so_its_pieces_have_somewhere_to_be(): void
    {
        $order = $this->order(10, ['cutting-bf' => 6, 'stitching-bf' => 2, 'qc-bf' => 0]);

        $this->assertSame(0, ProductionOrderBatch::where('production_order_id', $order->id)->count());

        app(LedgerBackfiller::class)->run();

        $batches = ProductionOrderBatch::where('production_order_id', $order->id)->get();
        $this->assertCount(1, $batches);
        $this->assertSame('All pieces', $batches->first()->label);
        $this->assertSame(10, (int) $batches->first()->loaded_qty);
    }

    public function test_a_completed_stage_is_treated_as_having_passed_everything(): void
    {
        $order = $this->order(10, ['cutting-bf' => 0]);

        ProductionTask::where('production_order_id', $order->id)
            ->whereHas('stage', fn ($q) => $q->where('slug', 'cutting-bf'))
            ->update(['status' => 'completed']);

        app(LedgerBackfiller::class)->run();

        $this->assertSame(10, $this->derivedPassed($order->fresh(), 'Cutting'));
    }

    /**
     * A counter that is higher than an upstream one is a historical anomaly, not
     * a reason to abort a deploy — clamping keeps the arithmetic non-negative.
     */
    public function test_counters_that_are_out_of_order_are_clamped_rather_than_fatal(): void
    {
        $order = $this->order(10, ['cutting-bf' => 2, 'stitching-bf' => 9, 'qc-bf' => 0]);

        app(LedgerBackfiller::class)->run();

        $positions = $this->positions($order);

        foreach ($positions as $stage => $qty) {
            $this->assertGreaterThanOrEqual(0, $qty, "{$stage} went negative.");
        }

        $this->assertSame(0, DB::table('v_batch_integrity')->where('is_balanced', false)->count());
        $this->assertSame(10, array_sum($positions));
    }

    public function test_running_the_backfill_twice_does_not_double_the_ledger(): void
    {
        $order = $this->order(10, ['cutting-bf' => 6, 'stitching-bf' => 2, 'qc-bf' => 0]);

        $first = app(LedgerBackfiller::class)->run();
        $count = PieceMovement::count();

        $second = app(LedgerBackfiller::class)->run();

        $this->assertSame($count, PieceMovement::count());
        $this->assertGreaterThan(0, $first['movements']);
        $this->assertSame(0, $second['movements']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(10, array_sum($this->positions($order)));
    }

    public function test_per_batch_counters_are_honoured_when_colourways_exist(): void
    {
        $order = $this->order(10, ['cutting-bf' => 0]);

        $blue  = ProductionOrderBatch::create([
            'production_order_id' => $order->id, 'label' => 'Blue',
            'quantity' => 6, 'sort_order' => 0,
        ]);
        $green = ProductionOrderBatch::create([
            'production_order_id' => $order->id, 'label' => 'Green',
            'quantity' => 4, 'sort_order' => 1,
        ]);

        $cutting = ProductionTask::where('production_order_id', $order->id)
            ->whereHas('stage', fn ($q) => $q->where('slug', 'cutting-bf'))
            ->firstOrFail();

        DB::table('production_task_batch_progress')->insert([
            ['production_task_id' => $cutting->id, 'production_order_batch_id' => $blue->id,  'quantity_done' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['production_task_id' => $cutting->id, 'production_order_batch_id' => $green->id, 'quantity_done' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(LedgerBackfiller::class)->run();

        $blueRows = DB::table('v_batch_stage_distribution')
            ->where('batch_id', $blue->id)->where('stage_name', 'Stitching')->value('qty_total');
        $greenRows = DB::table('v_batch_stage_distribution')
            ->where('batch_id', $green->id)->where('stage_name', 'Stitching')->value('qty_total');

        // Five blue and one green got past Cutting, so that is what stands at
        // Stitching — the colourways are migrated independently.
        $this->assertSame(5, (int) $blueRows);
        $this->assertSame(1, (int) $greenRows);
        $this->assertSame(0, DB::table('v_batch_integrity')->where('is_balanced', false)->count());
    }
}
