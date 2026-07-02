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
                ? " | ⚠️ Bal: {$fmt($r->total_balance)}"
                : '';

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' =>
                        "*{$r->user_name}* — {$r->outlet_name}\n" .
                        "{$r->order_count} order(s) | 💰 {$fmt($r->total_sales)} | ✅ Paid: {$fmt($r->total_paid)}{$balanceText}" .
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
                $orders = DB::table('orders as ord')
                    ->leftJoin(DB::raw("(
                        SELECT order_id, SUM(amount) as paid
                        FROM payments
                        WHERE status IN ('completed','approved','paid')
                        GROUP BY order_id
                    ) as p"), 'p.order_id', '=', 'ord.id')
                    ->where('ord.outlet_id', $row->outlet_id)
                    ->where('ord.created_by', $row->user_id)
                    ->whereDate('ord.created_at', $this->date)
                    ->whereNotIn('ord.status', ['voided', 'cancelled'])
                    ->where('ord.order_type', 'pos')
                    ->select([
                        DB::raw('COALESCE(SUM(p.paid), 0) as total_paid'),
                        DB::raw('SUM(ord.total_amount) as total_sales'),
                    ])
                    ->first();

                $totalSales   = (float) ($orders->total_sales ?? 0);
                $totalPaid    = (float) ($orders->total_paid  ?? 0);
                $totalBalance = max(0, $totalSales - $totalPaid);

                $orderCount = DB::table('orders')
                    ->where('outlet_id', $row->outlet_id)
                    ->where('created_by', $row->user_id)
                    ->whereDate('created_at', $this->date)
                    ->whereNotIn('status', ['voided', 'cancelled'])
                    ->where('order_type', 'pos')
                    ->count();

                return (object) [
                    'outlet_name'   => $row->outlet_name,
                    'user_name'     => trim($row->user_name) ?: 'Unknown',
                    'sentiments'    => $row->sentiments ?? '',
                    'order_count'   => $orderCount,
                    'total_sales'   => round($totalSales, 2),
                    'total_paid'    => round($totalPaid, 2),
                    'total_balance' => round($totalBalance, 2),
                ];
            });
    }
}