<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prove the floor still adds up.
 *
 * Conservation is structural — every movement is a matched debit and credit in
 * one transaction — so a row here is a bug in the engine, not a business
 * condition, and it should be treated like a failing test rather than an
 * operational nuisance.
 *
 * Two levels:
 *   --repair  replays the ledger over any unbalanced batch. Safe by
 *             construction: the ledger is the truth and the projection is
 *             derived, so a replay cannot invent state.
 *   --deep    additionally replays EVERY batch into a scratch comparison and
 *             reports any batch whose stored projection disagrees with what the
 *             ledger says it should be — the drift the balance check alone
 *             cannot see, because a projection can be self-consistent and still
 *             be wrong.
 *
 * Exits non-zero when anything is wrong, so CI and cron both notice.
 */
class CheckProductionIntegrity extends Command
{
    protected $signature = 'production:check-integrity
                            {--repair : Replay the ledger over unbalanced batches}
                            {--deep : Also verify every batch against a full replay}';

    protected $description = 'Verify that every production batch conserves its pieces';

    public function handle(): int
    {
        $unbalanced = DB::table('v_batch_integrity')->where('is_balanced', false)->get();

        if ($unbalanced->isEmpty()) {
            $this->info('Conservation holds: every batch balances.');
        } else {
            $this->error("{$unbalanced->count()} batch(es) do not balance:");
            $this->table(
                ['Batch', 'Order', 'Label', 'Loaded', 'Distributed'],
                $unbalanced->map(fn ($r) => [
                    $r->batch_id, $r->production_order_id, $r->label,
                    $r->loaded_qty, $r->distributed_qty,
                ])->all(),
            );

            if ($this->option('repair')) {
                foreach ($unbalanced as $row) {
                    DB::statement('SELECT fn_rebuild_batch_state(?)', [$row->batch_id]);
                    $this->line("  replayed batch {$row->batch_id}");
                }

                $still = DB::table('v_batch_integrity')->where('is_balanced', false)->count();

                if ($still === 0) {
                    $this->info('Repaired: all batches balance after replay.');
                    $unbalanced = collect();
                } else {
                    $this->error("{$still} batch(es) still unbalanced after replay.");
                }
            }
        }

        $drifted = $this->option('deep') ? $this->findDrift() : collect();

        return $unbalanced->isEmpty() && $drifted->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Compare every stored projection against a replay of its ledger.
     *
     * The replay is done inside a transaction that is always rolled back, so
     * this is strictly read-only from the caller's point of view even though it
     * writes while it works.
     */
    private function findDrift()
    {
        $this->line('Verifying every batch against a full replay…');

        $before = $this->snapshotAll();
        $drift  = collect();

        DB::beginTransaction();

        try {
            foreach ($before->keys() as $batchId) {
                DB::statement('SELECT fn_rebuild_batch_state(?)', [$batchId]);
            }

            $after = $this->snapshotAll();

            foreach ($before as $batchId => $rows) {
                if (($after[$batchId] ?? null) !== $rows) {
                    $drift->push($batchId);
                }
            }
        } finally {
            DB::rollBack();
        }

        if ($drift->isEmpty()) {
            $this->info('No drift: every projection matches its ledger.');
        } else {
            $this->error('Projection drift on batch(es): '.$drift->implode(', '));
            $this->line('Run with --repair to replay them.');
        }

        return $drift;
    }

    private function snapshotAll()
    {
        return DB::table('batch_stage_states')
            ->orderBy('production_order_batch_id')
            ->orderBy('production_workflow_stage_id')
            ->get()
            ->groupBy('production_order_batch_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                (int) $r->production_workflow_stage_id,
                (int) $r->qty_ready,
                (int) $r->qty_held,
            ])->all());
    }
}
