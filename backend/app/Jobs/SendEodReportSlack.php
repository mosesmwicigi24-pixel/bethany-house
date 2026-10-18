<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DEPLOY TO: app/Jobs/SendEodReportSlack.php
 *
 * Queries submitted EoD reports for the given date + outlet scope and posts
 * a formatted Block Kit message to the configured Slack Incoming Webhook.
 * Dispatched by:
 *  - SendEodReports artisan command (scheduled nightly)
 *  - PosController::testEodDelivery (on-demand test)
 */
class SendEodReportSlack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $date,
        private readonly array  $outletIds,   // empty = all outlets
    ) {}

    public function handle(): void
    {
        $reports = $this->fetchReports();

        if ($reports->isEmpty()) {
            Log::info("EoD Slack: no submitted reports for {$this->date}, skipping.");
            return;
        }

        $payload = $this->buildPayload($reports);

        $response = Http::timeout(15)->post($this->webhookUrl, $payload);

        if ($response->failed()) {
            Log::error("EoD Slack delivery failed: {$response->status()} — {$response->body()}");
            $this->fail(new \RuntimeException("Slack webhook returned {$response->status()}"));
            return;
        }

        Log::info("EoD Slack report sent for {$this->date} ({$reports->count()} cashier(s)).");
    }

    // ── Payload builder ───────────────────────────────────────────────────────

    private function buildPayload(\Illuminate\Support\Collection $reports): array
    {
        $dateFormatted = \Carbon\Carbon::parse($this->date)->format('l, j F Y');
        $totalSales    = $reports->sum('total_sales');
        $totalPaid     = $reports->sum('total_paid');
        $totalBalance  = $reports->sum('total_balance');
        $fmt           = fn ($n) => 'KES ' . number_format($n, 2);

        $blocks = [
            // Header
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => "📋 End of Day Report — {$dateFormatted}",
                    'emoji' => true,
                ],
            ],
            // Summary KPIs
            [
                'type'   => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Total Sales*\n{$fmt($totalSales)}"],
                    ['type' => 'mrkdwn', 'text' => "*Total Paid*\n{$fmt($totalPaid)}"],
                    ['type' => 'mrkdwn', 'text' => "*Outstanding Balance*\n{$fmt($totalBalance)}"],
                    ['type' => 'mrkdwn', 'text' => "*Cashiers Submitted*\n{$reports->count()}"],
                ],
            ],
            ['type' => 'divider'],
        ];

        // Per-cashier section
        foreach ($reports as $r) {
            $sentimentsText = $r->sentiments
                ? "\n>_" . strip_tags(str_replace(['</p>', '<br>', '<li>'], "\n", $r->sentiments)) . '_'
                : '';

            $balanceText = $r->total_balance > 0.01
                ? " | ⚠️ Bal: {$r->balance_label}"
                : '';

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' =>
                        "*{$r->user_name}* — {$r->outlet_name}\n" .
                        "{$r->order_count} order(s) | 💰 {$r->sales_label} | ✅ Paid: {$r->paid_label}{$balanceText}" .
                        $sentimentsText,
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => "Sent by Bethany House · " . now()->format('d M Y, H:i'),
            ]],
        ];

        return ['blocks' => $blocks];
    }

    // ── Data fetcher ──────────────────────────────────────────────────────────

    private function fetchReports(): \Illuminate\Support\Collection
    {
        return DB::table('cash_register_eod_reports as r')
            ->join('users as u',   'u.id', '=', 'r.user_id')
            ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->whereDate('r.report_date', $this->date)
            ->whereNotNull('r.submitted_at')
            ->when(!empty($this->outletIds), fn ($q) => $q->whereIn('r.outlet_id', $this->outletIds))
            ->select([
                'r.sentiments',
                'r.order_notes',
                'r.outlet_id',
                'r.user_id',
                'o.name as outlet_name',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
            ])
            ->orderBy('o.name')
            ->orderBy('u.first_name')
            ->get()
            ->map(function ($row) {
                // Per currency with a KES total at each order's own reporting
                // rate (Step 5); settled money only.
                $rows = DB::table('orders as ord')
                    ->leftJoin(DB::raw("(
                        SELECT order_id, SUM(amount - COALESCE(refund_amount, 0)) as paid
                        FROM payments
                        WHERE " . \App\Support\SettledPayment::sql('payments') . "
                        GROUP BY order_id
                    ) as p"), 'p.order_id', '=', 'ord.id')
                    ->where('ord.outlet_id', $row->outlet_id)
                    ->where('ord.created_by', $row->user_id)
                    ->whereDate('ord.created_at', $this->date)
                    ->whereNotIn('ord.status', ['voided', 'cancelled'])
                    // Confirmed or paid only (Step 5): a cart is not a sale.
                    ->where(fn ($q) => $q->whereIn('ord.status', \App\Models\Order::RECOGNISED_STATUSES)->orWhereIn('ord.payment_status', \App\Models\Order::SETTLED_PAYMENT_STATUSES))
                    ->where('ord.order_type', 'pos')
                    ->get(['ord.total_amount', 'ord.currency_code', 'ord.reporting_rate_to_kes', DB::raw('COALESCE(p.paid, 0) AS amount_paid')])
                    ->map(fn ($o) => (object) ['total_amount' => (float) $o->total_amount, 'amount_paid' => (float) $o->amount_paid,
                        'balance' => max(0, (float) $o->total_amount - (float) $o->amount_paid),
                        'currency_code' => $o->currency_code, 'reporting_rate_to_kes' => $o->reporting_rate_to_kes]);
                $salesMoney   = \App\Support\MoneyByCurrency::of($rows, 'total_amount', 'currency_code', 'reporting_rate_to_kes');
                $paidMoney    = \App\Support\MoneyByCurrency::of($rows, 'amount_paid', 'currency_code', 'reporting_rate_to_kes');
                $balanceMoney = \App\Support\MoneyByCurrency::of($rows, 'balance', 'currency_code', 'reporting_rate_to_kes');
                $totalSales   = $salesMoney['kes_total'];
                $totalPaid    = $paidMoney['kes_total'];
                $totalBalance = $balanceMoney['kes_total'];

                $orderCount = $rows->count();

                return (object) [
                    'outlet_name'   => $row->outlet_name,
                    'user_name'     => trim($row->user_name) ?: 'Unknown',
                    'sentiments'    => $row->sentiments ?? '',
                    'order_count'   => $orderCount,
                    'total_sales'   => round($totalSales, 2),
                    'total_paid'    => round($totalPaid, 2),
                    'total_balance' => round($totalBalance, 2),
                    'sales_label'   => \App\Support\MoneyByCurrency::label($salesMoney),
                    'paid_label'    => \App\Support\MoneyByCurrency::label($paidMoney),
                    'balance_label' => \App\Support\MoneyByCurrency::label($balanceMoney),
                ];
            });
    }
}