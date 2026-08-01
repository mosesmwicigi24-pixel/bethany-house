<?php

namespace App\Console\Commands;

use App\Services\Production\LedgerBackfiller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the movement ledger from the stage counters an order is running on.
 *
 * The deploy migration does this once. This command exists for everything
 * after: orders imported from a spreadsheet, a batch created before the ledger
 * was pinned, or a re-run after fixing historical data. Batches that already
 * carry movements are skipped, so running it twice changes nothing.
 */
class BackfillProductionLedger extends Command
{
    protected $signature = 'production:backfill-ledger {--dry-run : Report what would be written, then roll back}';

    protected $description = 'Convert legacy stage counters into piece-movement history';

    public function handle(LedgerBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            DB::beginTransaction();
        }

        try {
            $stats = $backfiller->run(function ($order) {
                $this->line("  {$order->order_number}");
            });
        } catch (\Throwable $e) {
            $dry && DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dry) {
            DB::rollBack();
            $this->warn('Dry run — nothing was kept.');
        }

        $this->table(
            ['Orders', 'Batches', 'Movements', 'Skipped'],
            [[$stats['orders'], $stats['batches'], $stats['movements'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }
}
