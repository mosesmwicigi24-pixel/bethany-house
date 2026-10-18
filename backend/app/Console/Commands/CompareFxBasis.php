<?php

namespace App\Console\Commands;

use App\Services\Reporting\MetricEngine;
use App\Support\ReportingCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Currency plan Step 3: what the reports say on the STORED-rate basis (each
 * row at the reporting rate it was made under) against the CURRENT-rate
 * basis every report used before — the same period, the same engine, only
 * the session's bethany.fx_basis switched. Read-only; safe on production.
 *
 *   php artisan reports:fx-basis-compare --from=2026-08-01 --to=2026-10-18
 *
 * If no reporting rate has changed since it was set, every line is 0.00.
 * A difference names the metric and the period it moved in; the per-source
 * table says how many rows carry which kind of stamp.
 */
class CompareFxBasis extends Command
{
    protected $signature = 'reports:fx-basis-compare
        {--from= : Period start (Y-m-d, default the first of last month)}
        {--to= : Period end (Y-m-d, default today)}';

    protected $description = 'Compare report figures on the stored reporting-rate basis against the current-rate basis';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('The basis switch is a Postgres session setting; this command needs the pgsql connection.');

            return self::FAILURE;
        }

        $tz   = config('app.timezone');
        $from = Carbon::parse($this->option('from') ?: now($tz)->subMonthNoOverflow()->startOfMonth()->toDateString(), $tz)->startOfDay();
        $to   = Carbon::parse($this->option('to') ?: now($tz)->toDateString(), $tz)->endOfDay();
        $len  = $from->diffInDays($to) + 1;
        $pf   = $from->copy()->subDays($len);
        $pe   = $from->copy()->subSecond();
        $this->info("Period {$from->toDateString()} → {$to->toDateString()} (previous {$pf->toDateString()} → {$pe->toDateString()})");

        $engineFor = fn () => MetricEngine::unscoped();
        $metrics = [
            'revenue (sales truth)'     => fn (MetricEngine $m) => (float) ($m->revenue($from, $to, $pf, $pe)['current'] ?? 0),
            'collected (money truth)'   => fn (MetricEngine $m) => (float) ($m->collected($from, $to, $pf, $pe)['current'] ?? 0),
            'earned revenue (P&L)'      => fn (MetricEngine $m) => (float) ($m->earnedPnl($from, $to)['revenue'] ?? 0),
            'earned gross profit (P&L)' => fn (MetricEngine $m) => (float) ($m->earnedPnl($from, $to)['gross_profit'] ?? 0),
            'outstanding balance'       => fn (MetricEngine $m) => (float) ($m->outstandingBalance()['total'] ?? 0),
            'open purchase orders'      => fn (MetricEngine $m) => (float) ($m->openPurchaseOrders()['exposure'] ?? $m->openPurchaseOrders()['total'] ?? 0),
        ];

        $rows = [];
        foreach ($metrics as $label => $fn) {
            $out = [];
            foreach (['stored', 'current'] as $basis) {
                ReportingCurrency::useBasis($basis);
                try {
                    $out[$basis] = round($fn($engineFor()), 2);
                } catch (\Illuminate\Database\QueryException $e) {
                    throw $e;   // a broken query is never a figure to print beside
                } catch (\Throwable $e) {
                    $out[$basis] = null;
                    $this->warn("{$label} [{$basis}]: {$e->getMessage()}");
                }
            }
            $diff = ($out['stored'] === null || $out['current'] === null) ? null : round($out['stored'] - $out['current'], 2);
            $rows[] = [$label, self::money($out['stored']), self::money($out['current']), $diff === null ? '—' : self::money($diff)];
        }
        ReportingCurrency::useBasis('stored');

        $this->table(['Metric (KES)', 'Stored-rate basis', 'Current-rate basis', 'Difference'], $rows);

        // What the rows carry.
        $this->newLine();
        $this->line('Stamps by source:');
        $stamps = [];
        foreach (['orders', 'payments', 'quotations', 'purchase_orders'] as $t) {
            $by = DB::table($t)->selectRaw("COALESCE(reporting_rate_source, '(unstamped)') AS source, UPPER(currency_code) AS code, COUNT(*) AS n")
                ->groupByRaw("COALESCE(reporting_rate_source, '(unstamped)'), UPPER(currency_code)")->orderBy('code')->orderBy('source')->get();
            foreach ($by as $r) {
                $stamps[] = [$t, $r->code, $r->source, (int) $r->n];
            }
        }
        $this->table(['Table', 'Currency', 'Source', 'Rows'], $stamps);

        $moved = array_filter($rows, fn ($r) => $r[3] !== '—' && $r[3] !== '0.00' && $r[3] !== '-0.00');
        $this->newLine();
        $this->info($moved === []
            ? 'No report figure moves between the two bases for this period.'
            : count($moved) . ' figure(s) differ between the bases — the stored basis is what the books now say; the difference is the effect of rate changes since those rows were made.');

        return self::SUCCESS;
    }

    private static function money(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }
}
