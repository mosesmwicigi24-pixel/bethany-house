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
                // The two the per-cashier lookup below joins on. They were
                // missing, and the `?? 0` that used to guard their absence
                // turned "I do not have this column" into "match nothing",
                // so every cashier's takings came out as zero.
                'r.outlet_id',
                'r.user_id',
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
                        SELECT order_id, SUM(amount - COALESCE(refund_amount, 0)) as paid
                        FROM payments
                        WHERE " . \App\Support\SettledPayment::sql('payments') . "
                        GROUP BY order_id
                    ) as p"), 'p.order_id', '=', 'ord.id')
                    // No `?? 0` here on purpose. A missing column should break
                    // loudly rather than quietly filter on an id nothing has
                    // and report a zero day, which is what it did for as long
                    // as this job has been running.
                    ->where('ord.outlet_id', $row->outlet_id)
                    ->where('ord.created_by', $row->user_id)
                    ->whereDate('ord.created_at', $this->date)
                    ->whereNotIn('ord.status', ['voided', 'cancelled'])
                    // Confirmed or paid only (Step 5): a cart is not a sale.
                    ->where(fn ($q) => $q->whereIn('ord.status', \App\Models\Order::RECOGNISED_STATUSES)->orWhereIn('ord.payment_status', \App\Models\Order::SETTLED_PAYMENT_STATUSES))
                    ->where('ord.order_type', 'pos')
                    ->select([
                        'ord.id',
                        'ord.order_number',
                        DB::raw("TRIM(CONCAT(COALESCE(ord.customer_first_name,''), ' ', COALESCE(ord.customer_last_name,''))) as customer_name"),
                        'ord.total_amount',
                        'ord.currency_code',
                        'ord.reporting_rate_to_kes',
                        DB::raw('COALESCE(p.paid, 0) as amount_paid'),
                    ])
                    ->get()
                    ->map(fn ($o) => [
                        'order_number'  => $o->order_number,
                        'customer_name' => trim($o->customer_name) ?: 'Walk-in',
                        'currency_code' => strtoupper((string) ($o->currency_code ?: 'KES')),
                        'reporting_rate_to_kes' => $o->reporting_rate_to_kes,
                        'total_amount'  => (float) $o->total_amount,
                        'amount_paid'   => (float) $o->amount_paid,
                        'balance'       => max(0, (float) $o->total_amount - (float) $o->amount_paid),
                        'eod_note'      => json_decode($row->order_notes ?? '{}', true)[strval($o->id)] ?? null,
                    ]);

                // Per currency with a KES total at each order's own reporting
                // rate (Step 5): the mail used to add dollars to shillings.
                $salesMoney   = \App\Support\MoneyByCurrency::of($orders, 'total_amount', 'currency_code', 'reporting_rate_to_kes');
                $paidMoney    = \App\Support\MoneyByCurrency::of($orders, 'amount_paid', 'currency_code', 'reporting_rate_to_kes');
                $balanceMoney = \App\Support\MoneyByCurrency::of($orders, 'balance', 'currency_code', 'reporting_rate_to_kes');
                $totalSales   = $salesMoney['kes_total'];
                $totalPaid    = $paidMoney['kes_total'];
                $totalBalance = $balanceMoney['kes_total'];

                return (object) [
                    'outlet_name'   => $row->outlet_name,
                    'user_name'     => trim($row->user_name) ?: 'Unknown',
                    'submitted_at'  => $row->submitted_at,
                    'sentiments'    => $row->sentiments ?? '',
                    'order_count'   => $orders->count(),
                    'total_sales'   => round($totalSales, 2),
                    'total_paid'    => round($totalPaid, 2),
                    'total_balance' => round($totalBalance, 2),
                    'sales_label'   => \App\Support\MoneyByCurrency::label($salesMoney),
                    'paid_label'    => \App\Support\MoneyByCurrency::label($paidMoney),
                    'balance_label' => \App\Support\MoneyByCurrency::label($balanceMoney),
                    'orders'        => $orders->toArray(),
                ];
            });
    }
}