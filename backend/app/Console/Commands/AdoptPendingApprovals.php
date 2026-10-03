<?php

namespace App\Console\Commands;

use App\Services\Approvals\ApprovalEngine;
use Illuminate\Console\Command;

/**
 * php artisan approvals:adopt-pending [--dry-run]
 *
 * Records that were already waiting for approval when the engine arrived
 * (Phase 3B) have no approval request, so they are not in anyone's inbox —
 * although the record-level approve buttons adopt them on first use. This
 * gives each one a request now, with its own maker (submitter, else creator),
 * valued and banded like a fresh submission. Idempotent: a record that has an
 * open request is skipped. It writes production data, so it is a deliberate
 * step, not part of a migration.
 */
class AdoptPendingApprovals extends Command
{
    protected $signature   = 'approvals:adopt-pending {--dry-run : List what would be adopted, write nothing}';
    protected $description = 'Give records already waiting for approval a request in the approval engine.';

    private const WAITING = [
        'purchase_order'   => [\App\Models\PurchaseOrder::class,        'status', 'pending_approval'],
        'stock_adjustment' => [\App\Models\InventoryTransaction::class, 'status', 'pending_approval'],
        'stock_transfer'   => [\App\Models\InventoryTransfer::class,    'status', 'pending'],
        'expense'          => [\App\Models\Expense::class,              'status', 'pending_approval'],
        'imprest_topup'    => [\App\Models\ImprestTopupRequest::class,  'status', 'pending'],
    ];

    public function handle(ApprovalEngine $engine): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::WAITING as $event => [$class, $column, $value]) {
            $handler = $engine->handler($event);
            $query = method_exists($class, 'withoutViewerScope') ? $class::withoutViewerScope() : $class::query();
            $n = 0;
            foreach ($query->where($column, $value)->orderBy('id')->cursor() as $record) {
                if (!$handler->isAwaitingApproval($record) || $engine->openRequest($event, $record)) {
                    continue;
                }
                $latest = $engine->latestRequest($event, $record);
                if ($latest && in_array($latest->status, ['rejected', 'expired'], true)) {
                    continue;   // went back to its maker; theirs to resubmit
                }
                $n++;
                if (!$dry) {
                    $engine->openOrAdopt($event, $record);
                }
            }
            $this->line(sprintf('  %-17s %d %s', $event, $n, $dry ? 'would be adopted' : 'adopted'));
            $total += $n;
        }

        $this->info(($dry ? 'Dry run: ' : '') . "{$total} record(s).");

        return self::SUCCESS;
    }
}
