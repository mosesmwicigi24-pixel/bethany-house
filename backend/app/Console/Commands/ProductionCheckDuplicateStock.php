<?php

namespace App\Console\Commands;

use App\Models\ProductionOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY. Finds production orders whose finished goods were put into stock
 * more than once.
 *
 * Before the floor-work guard, a piece correction on a completed order sent it
 * back to qc_pending, and completing it again posted a second stock-in; a
 * double-click on Complete & Stock could do the same (audit B2). This command
 * shows whether that ever happened. It changes nothing: any correction is a
 * separate, approved data change.
 *
 * Evidence, per order:
 *   1. inventory_transactions rows of type 'production' referencing the order
 *      (written by InventoryItem::adjustQuantity in ProductionController::complete)
 *   2. activity_log 'production_completed' entries for the order
 *
 * One stock-in per completed order is expected. More than one is a duplicate;
 * the extra quantity is the stock overstatement to investigate.
 */
class ProductionCheckDuplicateStock extends Command
{
    protected $signature = 'production:check-duplicate-stock
                            {--json : Print the findings as JSON}';

    protected $description = 'READ-ONLY: list production orders whose finished goods entered stock more than once';

    public function handle(): int
    {
        $refTypes = [ProductionOrder::class, 'production_order'];

        $stockIns = DB::table('inventory_transactions')
            ->where('transaction_type', 'production')
            ->whereIn('reference_type', $refTypes)
            ->whereNotNull('reference_id')
            ->orderBy('id')
            ->get(['id', 'reference_id', 'inventory_item_id', 'quantity_change', 'created_at', 'created_by'])
            ->groupBy('reference_id');

        $completions = DB::table('activity_log')
            ->where('event', 'production_completed')
            ->where('subject_type', ProductionOrder::class)
            ->select('subject_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('subject_id')
            ->pluck('n', 'subject_id');

        $suspectIds = $stockIns->filter(fn ($rows) => $rows->count() > 1)->keys()
            ->merge($completions->filter(fn ($n) => $n > 1)->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $orders = DB::table('production_orders')
            ->whereIn('id', $suspectIds)
            ->get(['id', 'order_number', 'status', 'quantity', 'completed_at'])
            ->keyBy('id');

        $findings = $suspectIds->map(function (int $id) use ($stockIns, $completions, $orders) {
            $rows  = $stockIns->get($id, collect());
            $order = $orders->get($id);
            $added = (float) $rows->sum('quantity_change');
            $first = (float) ($rows->first()->quantity_change ?? 0);

            return [
                'production_order_id' => $id,
                'order_number'        => $order->order_number ?? '(deleted)',
                'status'              => $order->status ?? null,
                'order_quantity'      => $order ? (int) $order->quantity : null,
                'stock_in_rows'       => $rows->count(),
                'completion_log_rows' => (int) $completions->get($id, 0),
                'quantity_added'      => $added,
                'excess_quantity'     => $added - $first,
                'transactions'        => $rows->map(fn ($r) => [
                    'id'                => (int) $r->id,
                    'inventory_item_id' => (int) $r->inventory_item_id,
                    'quantity_change'   => (float) $r->quantity_change,
                    'created_at'        => (string) $r->created_at,
                    'created_by'        => $r->created_by ? (int) $r->created_by : null,
                ])->values()->all(),
            ];
        })->values();

        $summary = [
            'orders_checked_with_stock_in' => $stockIns->count(),
            'suspect_orders'               => $findings->count(),
            'excess_quantity_total'        => (float) $findings->sum('excess_quantity'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode(['summary' => $summary, 'findings' => $findings], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Read-only check - nothing was changed.');
        $this->line("Orders with a finished-goods stock-in: {$summary['orders_checked_with_stock_in']}");

        if ($findings->isEmpty()) {
            $this->info('No production order entered stock more than once.');
            return self::SUCCESS;
        }

        $this->warn("{$summary['suspect_orders']} order(s) show more than one stock-in or completion. "
            . "Excess quantity: {$summary['excess_quantity_total']}");

        $this->table(
            ['Order', 'Status', 'Qty', 'Stock-ins', 'Completions', 'Added', 'Excess', 'Transaction ids'],
            $findings->map(fn ($f) => [
                $f['order_number'],
                $f['status'],
                $f['order_quantity'],
                $f['stock_in_rows'],
                $f['completion_log_rows'],
                $f['quantity_added'],
                $f['excess_quantity'],
                implode(', ', array_column($f['transactions'], 'id')),
            ])->all(),
        );

        $this->line('A completion logged twice with one stock-in means the order was re-completed without a second stock-in.');
        $this->line('Corrections need a separate, approved data change - this command never writes.');

        return self::SUCCESS;
    }
}
