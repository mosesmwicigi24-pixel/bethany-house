<?php

namespace App\Console\Commands;

use App\Services\History\HistoryAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Currency plan Step 6: list every record written in the wrong currency.
 * Read-only; safe on production. This is also the plan's Step 0 measure.
 *
 *   php artisan history:audit
 *   php artisan history:audit --csv=storage/app/history-audit
 */
class HistoryAuditCommand extends Command
{
    protected $signature = 'history:audit
        {--csv= : Directory to write one CSV per class into (created if missing)}';

    protected $description = 'List every till, order line, shipping fee, payment, budget and record in the wrong currency (read-only)';

    public function handle(HistoryAudit $audit): int
    {
        $dir = $this->option('csv');
        if ($dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->error("Cannot create {$dir}.");

                return self::FAILURE;
            }
        }

        $this->line('History audit (currency plan Step 6) — read-only.');

        // 1. tills
        $tills = $audit->tills();
        $this->section('1. Foreign cash rung through a till', $tills->count(), 'tills');
        $tillRows = [];
        foreach ($tills as $t) {
            foreach ($t['currencies'] as $c) {
                $tillRows[] = [
                    'till'            => "#{$t['register_id']} {$t['register_name']}",
                    'status'          => $t['status'] . ($t['finalized'] ? ' (finalized)' : ''),
                    'home'            => $t['home'],
                    'stored_expected' => $t['stored_expected'],
                    'ledger_expected' => $t['ledger_expected'],
                    'difference'      => $t['difference'],
                    'currency'        => $c['currency'],
                    'net_cash'        => $c['net'],
                    'sales'           => $c['sale_count'],
                    'has_row'         => $c['has_balance_row'] ? 'yes' : 'no',
                ];
            }
        }
        $this->show($tillRows, $dir, 'tills');

        // 2. lines
        $lines = $audit->linesPricedInAnotherCurrency();
        $this->section('2. Order lines priced from another currency', $lines->count(), 'lines');
        $this->show($lines->map(fn ($l) => [
            'order' => $l['order_number'], 'status' => $l['status'], 'currency' => $l['currency'], 'product' => $l['product'],
            'qty' => $l['quantity'], 'unit_price' => $l['unit_price'], 'priced_in' => $l['priced_in'],
            'expected' => $l['expected'] ?? 'UNKNOWN', 'note' => $l['expected_note'],
        ])->all(), $dir, 'lines');

        // 3. shipping
        $ship = $audit->kesShippingOnForeignOrders();
        $this->section('3. KES shipping fee on a foreign order', $ship->count(), 'orders');
        $this->show($ship->map(fn ($s) => [
            'order' => $s['order_number'], 'status' => $s['status'], 'currency' => $s['currency'],
            'shipping' => $s['shipping_amount'], 'method' => $s['method'], 'kes_fee' => $s['method_kes_fee'],
            'expected' => $s['expected'] ?? 'UNKNOWN',
        ])->all(), $dir, 'shipping');

        // 4. payments
        $pay = $audit->paymentsInAnotherCurrency();
        $this->section('4. Payment in a different currency from its order', $pay->count(), 'payments');
        $this->show($pay->map(fn ($p) => [
            'payment' => $p['payment_number'], 'order' => $p['order_number'], 'paid_in' => $p['payment_currency'],
            'order_in' => $p['order_currency'], 'amount' => $p['amount'], 'refunded' => $p['refund_amount'],
            'status' => $p['status'], 'method' => $p['method'], 'at' => $p['at'],
        ])->all(), $dir, 'payments');

        // 5. mpesa
        $mp = $audit->mpesaOnForeignOrders();
        $this->section('5. M-Pesa charged on a non-KES order', $mp->count(), 'payments');
        $this->show($mp->map(fn ($p) => [
            'payment' => $p['payment_number'], 'order' => $p['order_number'], 'order_in' => $p['order_currency'],
            'order_total' => $p['order_total'], 'received' => "{$p['payment_currency']} {$p['amount_received']}",
            'status' => $p['status'], 'receipt' => $p['mpesa_receipt'], 'at' => $p['at'],
        ])->all(), $dir, 'mpesa');

        // 6. no rate
        $nr = $audit->recordsWithoutRate();
        $total = array_sum(array_map(fn ($t) => $t['count'], $nr['tables']));
        $this->section('6. Records in a currency with no reporting rate', $total, 'records');
        $this->line('   Currencies without a rate: ' . ($nr['currencies'] ? implode(', ', $nr['currencies']) : 'none'));
        $this->show(collect($nr['tables'])->map(fn ($t, $table) => [
            'table' => $table, 'count' => $t['count'], 'sample' => implode('; ', array_slice($t['references'], 0, 10)),
        ])->values()->all(), $dir, 'no-rate');

        // 7. budgets
        $b = $audit->foreignBudgets();
        $this->section('7. Expense budgets not set in KES', $b->count(), 'budgets');
        $this->show($b->map(fn ($x) => [
            'budget' => $x['budget_id'], 'category' => $x['category'], 'outlet' => $x['outlet_id'] ?? 'company', 'period' => $x['period'],
            'amount' => "{$x['currency']} {$x['amount']}", 'rate' => $x['rate'] ?? 'none', 'kes' => $x['amount_kes'] ?? 'UNKNOWN',
        ])->all(), $dir, 'budgets');

        // 8. outlets
        $o = $audit->outletsInForeignCurrency();
        $this->section('8. Outlets whose country prices in another currency', $o->count(), 'outlets');
        $this->show($o->map(fn ($x) => [
            'outlet' => "#{$x['outlet_id']} {$x['outlet']}", 'country' => $x['country'], 'currency' => $x['currency'],
            'has_rate' => $x['has_rate'] ? 'yes' : 'no',
        ])->all(), $dir, 'outlets');

        $this->newLine();
        $this->line('Mechanical repairs: history:repair-tills, history:repair-budgets (dry run by default; --apply --actor=<user> to write).');
        $this->line('Classes 2–6 need a decision each: order line edit (correction record), set-shipping, payment move/void, currency rate proposal.');

        return self::SUCCESS;
    }

    private function section(string $title, int $count, string $noun): void
    {
        $this->newLine();
        $this->info("{$title}: {$count} {$noun}");
    }

    /** @param list<array<string, mixed>> $rows */
    private function show(array $rows, ?string $dir, string $file): void
    {
        if ($rows === []) {
            $this->line('   none');
        } else {
            $this->table(array_keys($rows[0]), array_map(fn ($r) => array_map(fn ($v) => $v === null ? '' : (string) $v, $r), $rows));
        }
        if ($dir) {
            $path = rtrim($dir, '/') . "/{$file}.csv";
            $fh   = fopen($path, 'w');
            if ($rows !== []) {
                fputcsv($fh, array_keys($rows[0]));
                foreach ($rows as $r) {
                    fputcsv($fh, array_map(fn ($v) => $v === null ? '' : (string) $v, $r));
                }
            }
            fclose($fh);
            $this->line("   → {$path}");
        }
    }
}
