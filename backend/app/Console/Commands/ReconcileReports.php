<?php

namespace App\Console\Commands;

use App\Services\Reporting\Reconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Currency plan Step 7: prove the books tie. Read-only; safe on production.
 *
 *   php artisan reports:reconcile --from=2026-10-01 --to=2026-10-18
 *   php artisan reports:reconcile --outlet=3 --csv=storage/app/reconcile
 *
 * Rebuilds Sales, Collected, Owed and Expected till cash from raw rows,
 * independently of the reporting engine, and sets each against the engine's
 * figure for the same question. Exit code 1 when any identity fails, with
 * every difference listed record by record.
 */
class ReconcileReports extends Command
{
    protected $signature = 'reports:reconcile
        {--from= : Period start (Y-m-d, default the first of this month)}
        {--to= : Period end (Y-m-d, default today)}
        {--outlet=* : Only these outlet ids (repeatable); default every outlet}
        {--csv= : Directory to write the till rows and the owed gaps as CSV}';

    protected $description = 'Rebuild Sales, Collected, Owed and till cash from raw rows and prove they tie to the reports';

    public function handle(): int
    {
        $tz   = config('app.timezone');
        $from = Carbon::parse($this->option('from') ?: now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay();
        $to   = Carbon::parse($this->option('to') ?: now($tz)->toDateString(), $tz)->endOfDay();
        $outlets = array_values(array_filter(array_map('intval', (array) $this->option('outlet'))));
        $rec  = new Reconciliation($outlets ?: null);
        $ok   = true;
        $fmt  = fn ($v) => $v === null ? 'UNKNOWN' : number_format((float) $v, 2);

        $this->line("Reconciliation {$from->toDateString()} → {$to->toDateString()}" . ($outlets ? ' outlets ' . implode(',', $outlets) : ' (every outlet)') . ' — read-only.');

        // 1. Sales = Till + Web + Chat + Quoted
        $sales = $rec->sales($from, $to);
        $this->newLine();
        $this->info('1. Sales = Till + Web + Chat + Quoted (recognised orders, by order date)');
        $rows = [];
        foreach ($sales['by_currency'] as $cur => $c) {
            foreach ($c['buckets'] as $b => $bb) {
                $rows[] = [$cur, $b, $bb['orders'], $fmt($bb['native']), $fmt($bb['kes'])];
            }
            $rows[] = [$cur, 'TOTAL', $c['orders'], $fmt($c['native']), $fmt($c['kes'])];
        }
        $rows === [] ? $this->line('   no recognised orders in the period') : $this->table(['currency', 'bucket', 'orders', 'native', 'KES'], $rows);
        $this->line(sprintf('   KES: till %s + web %s + chat %s + quoted %s = %s %s',
            $fmt($sales['bucket_kes']['till']), $fmt($sales['bucket_kes']['web']), $fmt($sales['bucket_kes']['chat']), $fmt($sales['bucket_kes']['quoted']),
            $fmt($sales['kes_total']), $sales['ties'] ? 'TIES' : 'DOES NOT TIE'));
        $ok = $ok && $sales['ties'];
        $this->unconverted($sales['unconverted']);

        // 2. Collected
        $collected = $rec->collected($from, $to);
        $this->newLine();
        $this->info('2. Collected = settled payments − their refunds (by payment date)');
        $rows = [];
        foreach ($collected['by_currency'] as $cur => $c) {
            $rows[] = [$cur, $c['payments'], $fmt($c['native']), $fmt($c['refunds']), $fmt($c['kes'])];
        }
        $rows === [] ? $this->line('   no settled payments in the period') : $this->table(['currency', 'payments', 'net native', 'refunds', 'KES'], $rows);
        $this->line('   KES total ' . $fmt($collected['kes_total']));
        $this->unconverted($collected['unconverted']);

        // 3. Owed
        $owed = $rec->owed();
        $this->newLine();
        $this->info('3. Owed = Sales − Collected, order by order (every recognised order)');
        $this->line(sprintf('   sales %s − collected %s − owed %s = %s, explained by: settled by a person %s − overpaid %s − money on a dead order %s → %s',
            $fmt($owed['sales_kes']), $fmt($owed['collected_kes']), $fmt($owed['owed_kes']), $fmt($owed['gap_kes']),
            $fmt($owed['gaps']['settled_by_person']['kes']), $fmt($owed['gaps']['overpaid']['kes']), $fmt($owed['gaps']['money_on_dead']['kes']),
            $owed['ties'] ? 'TIES' : 'DOES NOT TIE'));
        $ok = $ok && $owed['ties'];
        foreach ($owed['gaps'] as $kind => $g) {
            if ($g['records'] === []) {
                continue;
            }
            $this->line("   {$kind}: " . count($g['records']) . ' record(s), KES ' . $fmt($g['kes']));
            $this->table(array_keys($g['records'][0]), array_map(fn ($r) => array_map(fn ($v) => (string) $v, $r), array_slice($g['records'], 0, 50)));
        }
        $this->unconverted($owed['unconverted']);

        // 4. Against the engine
        $cmp = $rec->againstEngine($from, $to);
        $this->newLine();
        $this->info('4. The same questions put to MetricEngine (what the reports show)');
        $this->table(['figure', 'rebuilt from rows', 'engine', 'ties'], array_map(fn ($k, $v) => [
            $k, $fmt($v['independent']), $fmt($v['engine']), $v['ties'] ? 'TIES' : 'DOES NOT TIE',
        ], array_keys($cmp), $cmp));
        foreach ($cmp as $v) {
            $ok = $ok && $v['ties'];
        }

        // 5. Tills
        $tills = $rec->tills();
        $this->newLine();
        $this->info('5. Expected till cash per currency = opening + cash sales + cash in − refunds − voids − paid-outs');
        $bad = $tills->filter(fn ($t) => !$t['ties']);
        $this->line("   {$tills->count()} till/currency row(s); {$bad->count()} do not tie");
        if ($bad->isNotEmpty()) {
            $this->table(['till', 'currency', 'status', 'ledger', 'stated', 'difference', 'restated'], $bad->map(fn ($t) => [
                "#{$t['register_id']} {$t['register_name']}", $t['currency'], $t['status'], $fmt($t['ledger_expected']), $fmt($t['stated_expected']),
                $fmt($t['difference']), $t['restated'] ? 'yes' : 'no',
            ])->all());
            $ok = false;
        }

        if ($dir = $this->option('csv')) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->error("Cannot create {$dir}.");

                return self::FAILURE;
            }
            $this->csv("{$dir}/tills.csv", $tills->all());
            $gapRows = [];
            foreach ($owed['gaps'] as $kind => $g) {
                foreach ($g['records'] as $r) {
                    $gapRows[] = ['kind' => $kind] + $r;
                }
            }
            $this->csv("{$dir}/owed-gaps.csv", $gapRows);
            $this->line("   → {$dir}/tills.csv, {$dir}/owed-gaps.csv");
        }

        $this->newLine();
        $this->line($ok ? 'EVERYTHING TIES.' : 'SOMETHING DOES NOT TIE — see the rows above.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function unconverted(array $codes): void
    {
        if ($codes !== []) {
            $this->warn('   no reporting rate for ' . implode(', ', $codes) . ': those rows are reported apart, not converted and not added.');
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        if ($rows !== []) {
            $keys = array_keys($rows[0]);
            fputcsv($fh, $keys);
            foreach ($rows as $r) {
                fputcsv($fh, array_map(fn ($k) => isset($r[$k]) ? (is_bool($r[$k]) ? ($r[$k] ? 'yes' : 'no') : (string) $r[$k]) : '', $keys));
            }
        }
        fclose($fh);
    }
}
