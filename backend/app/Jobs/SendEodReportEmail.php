<?php

namespace App\Jobs;

use App\Mail\EodReportMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * DEPLOY TO: app/Jobs/SendEodReportEmail.php
 *
 * Queries submitted EoD reports for the given date + outlet scope,
 * builds the payload, and mails it to all configured recipients.
 * Dispatched by:
 *  - SendEodReports artisan command (scheduled nightly)
 *  - PosController::testEodDelivery (on-demand test)
 */
class SendEodReportEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly array  $recipients,
        private readonly string $date,
        private readonly array  $outletIds,   // empty = all outlets
    ) {}

    public function handle(): void
    {
        $reports = $this->fetchReports();

        if ($reports->isEmpty()) {
            Log::info("EoD email: no submitted reports found for {$this->date}, skipping.");
            return;
        }

        $mailable = new EodReportMail($this->date, $reports->toArray());

        foreach ($this->recipients as $address) {
            try {
                Mail::to($address)->send($mailable);
                Log::info("EoD email sent to {$address} for {$this->date}.");
            } catch (\Throwable $e) {
                Log::error("EoD email failed for {$address}: {$e->getMessage()}");
            }
        }
    }

    // ── Shared query logic ────────────────────────────────────────────────────

    private function fetchReports(): \Illuminate\Support\Collection
    {
        return DB::table('cash_register_eod_reports as r')
            ->join('users as u',   'u.id', '=', 'r.user_id')
            ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->whereDate('r.report_date', $this->date)
            ->whereNotNull('r.submitted_at')
            ->when(!empty($this->outletIds), fn ($q) => $q->whereIn('r.outlet_id', $this->outletIds))
            ->select([
                'r.id',
                'r.report_date',
                'r.submitted_at',
                'r.sentiments',
                'r.order_notes',
                'o.name as outlet_name',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
            ])
            ->orderBy('o.name')
            ->orderBy('u.first_name')
            ->get()
            ->map(function ($row) {
                // Attach order-level KPIs
                $orders = DB::table('orders as ord')
                    ->leftJoin(DB::raw("(
                        SELECT order_id, SUM(amount) as paid
                        FROM payments
                        WHERE status IN ('completed','approved','paid')
                        GROUP BY order_id
                    ) as p"), 'p.order_id', '=', 'ord.id')
                    ->where('ord.outlet_id', $row->outlet_id ?? 0)
                    ->where('ord.created_by', $row->user_id ?? 0)
                    ->whereDate('ord.created_at', $this->date)
                    ->whereNotIn('ord.status', ['voided', 'cancelled'])
                    ->where('ord.order_type', 'pos')
                    ->select([
                        'ord.id',
                        'ord.order_number',
                        DB::raw("TRIM(CONCAT(COALESCE(ord.customer_first_name,''), ' ', COALESCE(ord.customer_last_name,''))) as customer_name"),
                        'ord.total_amount',
                        DB::raw('COALESCE(p.paid, 0) as amount_paid'),
                    ])
                    ->get()
                    ->map(fn ($o) => [
                        'order_number'  => $o->order_number,
                        'customer_name' => trim($o->customer_name) ?: 'Walk-in',
                        'total_amount'  => (float) $o->total_amount,
                        'amount_paid'   => (float) $o->amount_paid,
                        'balance'       => max(0, (float) $o->total_amount - (float) $o->amount_paid),
                        'eod_note'      => json_decode($row->order_notes ?? '{}', true)[strval($o->id)] ?? null,
                    ]);

                $totalSales   = $orders->sum('total_amount');
                $totalPaid    = $orders->sum('amount_paid');
                $totalBalance = $orders->sum('balance');

                return (object) [
                    'outlet_name'   => $row->outlet_name,
                    'user_name'     => trim($row->user_name) ?: 'Unknown',
                    'submitted_at'  => $row->submitted_at,
                    'sentiments'    => $row->sentiments ?? '',
                    'order_count'   => $orders->count(),
                    'total_sales'   => round($totalSales, 2),
                    'total_paid'    => round($totalPaid, 2),
                    'total_balance' => round($totalBalance, 2),
                    'orders'        => $orders->toArray(),
                ];
            });
    }
}