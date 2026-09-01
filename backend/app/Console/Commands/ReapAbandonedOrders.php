<?php

namespace App\Console\Commands;

use App\Services\AbandonedOrderReaper;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

/**
 * Lists abandoned unpaid POS pending orders — and, only when a human passes
 * --force, cancels them and restores the stock they reserved.
 *
 * It used to run hourly and cancel automatically. It cancelled 12 real orders
 * worth KES 170,400 between July and August, including a KES 49,500 sale. Every
 * one was a customer a human had served and might still have closed.
 *
 * Owner's rule (2026-09-01): the system does not cancel receipts; only a person
 * does. So the default is a REPORT, and the schedule is gone. The standing
 * signal now lives in the Pending Queue, where the order, the customer and its
 * age are visible before anyone decides.
 */
class ReapAbandonedOrders extends Command
{
    protected $signature = 'pos:reap-abandoned-orders
        {--hours=24 : Minimum age in hours before an unpaid pending order is listed}
        {--force : Actually cancel them. Without this the command only reports.}';

    protected $description = 'List abandoned unpaid POS pending orders (use --force to cancel them)';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        if (!$this->option('force')) {
            $rows = DB::table('orders')
                ->where('order_type', 'pos')
                ->where('status', 'pending')
                ->where('payment_status', 'pending')
                ->where('created_at', '<', now()->subHours($hours))
                ->orderByDesc('total_amount')
                ->get(['order_number', 'customer_first_name', 'customer_last_name',
                       'total_amount', 'currency_code', 'created_at']);

            if ($rows->isEmpty()) {
                $this->info("No unpaid POS orders older than {$hours}h.");

                return self::SUCCESS;
            }

            $this->table(
                ['Order', 'Customer', 'Amount', 'Placed'],
                $rows->map(fn ($r) => [
                    $r->order_number,
                    trim(($r->customer_first_name ?? '') . ' ' . ($r->customer_last_name ?? '')) ?: '—',
                    $r->currency_code . ' ' . number_format((float) $r->total_amount, 2),
                    substr((string) $r->created_at, 0, 10),
                ])->all(),
            );
            $this->warn($rows->count() . " order(s) are stale. NOTHING has been cancelled.");
            $this->line('Work them in the Pending Queue, or pass --force to cancel them all.');

            return self::SUCCESS;
        }

        $result = AbandonedOrderReaper::reap($hours);
        $this->info("Reaped {$result['cancelled']} abandoned order(s); restored {$result['restored']} unit(s) to stock.");

        return self::SUCCESS;
    }
}
