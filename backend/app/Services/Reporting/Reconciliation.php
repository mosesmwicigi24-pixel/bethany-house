<?php

namespace App\Services\Reporting;

use App\Models\Order;
use App\Support\ReportingCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Currency plan Step 7 (owner, 2026-10-18): PROVE the books tie.
 *
 * Every figure here is rebuilt from raw rows in PHP — the recognised rule
 * and the settled rule written out, each row converted at its own stored
 * reporting rate (the live rate only for a row with none, never a guess)
 * — and then set against what MetricEngine says for the same question. The
 * two must agree; where an identity does not hold, the difference is listed
 * record by record and classified, never hidden in a total.
 *
 *   Sales      = Till + Web + Chat + Quoted        (recognised orders, by order date)
 *   Collected  = settled payments − their refunds  (by payment date)
 *   Owed       = Sales − Collected per open order, adjusted only for the
 *                three documented gaps (overpayment clamped, settled by a
 *                person, money on a dead order)
 *   Expected till cash, per currency
 *              = opening float + cash sales + cash in − cash refunds − voids − paid-outs
 *
 * Read-only. Nairobi business dates. An unconvertible currency is reported
 * apart (`unconverted`), never dropped and never added.
 */
class Reconciliation
{
    public const TOLERANCE = 0.01;

    /** @param list<int>|null $outletIds null = every outlet */
    public function __construct(private readonly ?array $outletIds = null)
    {
    }

    // ── sales ────────────────────────────────────────────────────────────────

    /**
     * @return array{by_currency: array<string, array{orders:int, native:float, kes:?float, buckets: array<string, array{orders:int, native:float, kes:?float}>}>, kes_total: float, bucket_kes: array<string, float>, unconverted: list<string>, ties: bool}
     */
    public function sales(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($this->outletIds, fn ($q) => $q->whereIn('orders.outlet_id', $this->outletIds))
            ->get(['id', 'order_number', 'status', 'payment_status', 'currency_code', 'total_amount', 'reporting_rate_to_kes',
                   DB::raw(Order::salesBucketSql('orders') . ' AS bucket')])
            ->filter(fn ($o) => self::recognised($o));

        $by = [];
        $unconverted = [];
        foreach ($rows as $o) {
            $cur = strtoupper((string) ($o->currency_code ?: 'KES'));
            $kes = self::kes((float) $o->total_amount, $cur, $o->reporting_rate_to_kes);
            if ($kes === null) {
                $unconverted[$cur] = true;
            }
            $by[$cur] ??= ['orders' => 0, 'native' => 0.0, 'kes' => 0.0, 'buckets' => []];
            $by[$cur]['orders']++;
            $by[$cur]['native'] = round($by[$cur]['native'] + (float) $o->total_amount, 2);
            $by[$cur]['kes']    = $kes === null ? null : ($by[$cur]['kes'] === null ? null : round($by[$cur]['kes'] + $kes, 2));
            $b = $o->bucket;
            $by[$cur]['buckets'][$b] ??= ['orders' => 0, 'native' => 0.0, 'kes' => 0.0];
            $by[$cur]['buckets'][$b]['orders']++;
            $by[$cur]['buckets'][$b]['native'] = round($by[$cur]['buckets'][$b]['native'] + (float) $o->total_amount, 2);
            $by[$cur]['buckets'][$b]['kes']    = $kes === null ? null : round(($by[$cur]['buckets'][$b]['kes'] ?? 0) + $kes, 2);
        }
        ksort($by);

        $kesTotal  = 0.0;
        $bucketKes = array_fill_keys(Order::SALES_BUCKETS, 0.0);
        foreach ($by as $cur => $c) {
            if ($c['kes'] === null) {
                continue;
            }
            $kesTotal += $c['kes'];
            foreach ($c['buckets'] as $b => $bb) {
                $bucketKes[$b] = round(($bucketKes[$b] ?? 0) + ($bb['kes'] ?? 0), 2);
            }
        }
        $kesTotal = round($kesTotal, 2);

        return [
            'by_currency' => $by,
            'kes_total'   => $kesTotal,
            'bucket_kes'  => $bucketKes,
            'unconverted' => array_keys($unconverted),
            'ties'        => abs(array_sum($bucketKes) - $kesTotal) < self::TOLERANCE,
        ];
    }

    // ── collected ────────────────────────────────────────────────────────────

    /** @return array{by_currency: array<string, array{payments:int, native:float, refunds:float, kes:?float}>, kes_total: float, unconverted: list<string>} */
    public function collected(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->whereBetween(DB::raw('COALESCE(p.paid_at, p.created_at)'), [$from, $to])
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds))
            ->get(['p.id', 'p.status', 'p.requires_approval', 'p.approval_status', 'p.currency_code', 'p.amount', 'p.refund_amount', 'p.reporting_rate_to_kes'])
            ->filter(fn ($p) => self::settled($p));

        $by = [];
        $unconverted = [];
        foreach ($rows as $p) {
            $cur = strtoupper((string) ($p->currency_code ?: 'KES'));
            $net = round((float) $p->amount - (float) ($p->refund_amount ?? 0), 2);
            $kes = self::kes($net, $cur, $p->reporting_rate_to_kes);
            if ($kes === null) {
                $unconverted[$cur] = true;
            }
            $by[$cur] ??= ['payments' => 0, 'native' => 0.0, 'refunds' => 0.0, 'kes' => 0.0];
            $by[$cur]['payments']++;
            $by[$cur]['native']  = round($by[$cur]['native'] + $net, 2);
            $by[$cur]['refunds'] = round($by[$cur]['refunds'] + (float) ($p->refund_amount ?? 0), 2);
            $by[$cur]['kes']     = $kes === null ? null : ($by[$cur]['kes'] === null ? null : round($by[$cur]['kes'] + $kes, 2));
        }
        ksort($by);

        return [
            'by_currency' => $by,
            'kes_total'   => round(array_sum(array_map(fn ($c) => $c['kes'] ?? 0, $by)), 2),
            'unconverted' => array_keys($unconverted),
        ];
    }

    // ── owed ─────────────────────────────────────────────────────────────────

    /**
     * Sales − Collected, order by order, over every recognised order (not a
     * period: a debt has no window). `owed_kes` is what the receivables page
     * must say. The gap between "sales − collected" and "owed" is listed and
     * classified so an accountant can see exactly where it comes from.
     *
     * @return array{sales_kes: float, collected_kes: float, owed_kes: float, gap_kes: float, gaps: array<string, array{kes: float, records: list<array<string, mixed>>}>, unconverted: list<string>}
     */
    public function owed(): array
    {
        $orders = DB::table('orders')
            ->when($this->outletIds, fn ($q) => $q->whereIn('orders.outlet_id', $this->outletIds))
            ->get(['id', 'order_number', 'status', 'payment_status', 'currency_code', 'total_amount', 'reporting_rate_to_kes']);

        $paidByOrder = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds))
            ->get(['p.order_id', 'p.id', 'p.status', 'p.requires_approval', 'p.approval_status', 'p.currency_code', 'p.amount', 'p.refund_amount', 'p.reporting_rate_to_kes'])
            ->filter(fn ($p) => self::settled($p))
            ->groupBy('order_id')
            ->map(function (Collection $ps) {
                $kes = 0.0;
                $unc = false;
                foreach ($ps as $p) {
                    $v = self::kes(round((float) $p->amount - (float) ($p->refund_amount ?? 0), 2), strtoupper((string) ($p->currency_code ?: 'KES')), $p->reporting_rate_to_kes);
                    if ($v === null) {
                        $unc = true;
                    } else {
                        $kes += $v;
                    }
                }

                return ['kes' => round($kes, 2), 'unconverted' => $unc];
            });

        $sales = $collected = $owed = 0.0;
        $gaps  = [
            'overpaid'          => ['kes' => 0.0, 'records' => []],   // paid more than the order: clamped to 0 owed
            'settled_by_person' => ['kes' => 0.0, 'records' => []],   // payment_status = paid with money still short: a human's call
            'money_on_dead'     => ['kes' => 0.0, 'records' => []],   // settled money on a cancelled/voided/refunded order
        ];
        $unconverted = [];

        foreach ($orders as $o) {
            $cur  = strtoupper((string) ($o->currency_code ?: 'KES'));
            $paid = $paidByOrder->get($o->id, ['kes' => 0.0, 'unconverted' => false]);
            if ($paid['unconverted']) {
                $unconverted[$cur] = true;
            }
            if (!self::recognised($o)) {
                if (in_array($o->status, Order::DEAD_STATUSES, true) && $paid['kes'] > 0) {
                    $gaps['money_on_dead']['kes'] = round($gaps['money_on_dead']['kes'] + $paid['kes'], 2);
                    $gaps['money_on_dead']['records'][] = ['order' => $o->order_number, 'status' => $o->status, 'collected_kes' => $paid['kes']];
                    $collected += $paid['kes'];
                }
                continue;
            }
            $total = self::kes((float) $o->total_amount, $cur, $o->reporting_rate_to_kes);
            if ($total === null) {
                $unconverted[$cur] = true;
                continue;
            }
            $sales     += $total;
            $collected += $paid['kes'];
            $diff       = round($total - $paid['kes'], 2);
            if ($diff < 0) {
                $gaps['overpaid']['kes'] = round($gaps['overpaid']['kes'] - $diff, 2);
                $gaps['overpaid']['records'][] = ['order' => $o->order_number, 'total_kes' => $total, 'collected_kes' => $paid['kes'], 'over_kes' => -$diff];
                continue;
            }
            if ($diff > 0 && $o->payment_status === 'paid') {
                $gaps['settled_by_person']['kes'] = round($gaps['settled_by_person']['kes'] + $diff, 2);
                $gaps['settled_by_person']['records'][] = ['order' => $o->order_number, 'total_kes' => $total, 'collected_kes' => $paid['kes'], 'short_kes' => $diff];
                continue;
            }
            $owed += $diff;
        }

        $sales = round($sales, 2);
        $collected = round($collected, 2);
        $owed = round($owed, 2);

        return [
            'sales_kes'     => $sales,
            'collected_kes' => $collected,
            'owed_kes'      => $owed,
            // Sales − Collected − Owed = settled_by_person − overpaid − money_on_dead, exactly.
            'gap_kes'       => round($sales - $collected - $owed, 2),
            'gaps'          => $gaps,
            'unconverted'   => array_keys($unconverted),
            'ties'          => abs(($sales - $collected - $owed) - ($gaps['settled_by_person']['kes'] - $gaps['overpaid']['kes'] - $gaps['money_on_dead']['kes'])) < self::TOLERANCE,
        ];
    }

    // ── tills ────────────────────────────────────────────────────────────────

    /**
     * Expected cash per till and currency, rebuilt from the ledger, against the
     * balance row the till screens show. Every row is listed; `ties` says whether
     * the two agree. A finalized till restated by a history correction is shown
     * with the correction's figure as the stated expected.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tills(): Collection
    {
        $registers = DB::table('cash_registers')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->orderBy('id')
            ->get(['id', 'register_name', 'outlet_id', 'status', 'currency_code', 'opening_balance', 'finalized_at']);

        $ledger = DB::table('cash_register_transactions')
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', 'cash'))
            ->whereIn('cash_register_id', $registers->pluck('id'))
            ->groupBy('cash_register_id', 'currency_code', 'transaction_type')
            ->selectRaw('cash_register_id, UPPER(currency_code) AS currency_code, transaction_type, COALESCE(SUM(ABS(amount)), 0) AS total')
            ->get()
            ->groupBy('cash_register_id');

        $balances = DB::table('cash_register_balances')
            ->whereIn('cash_register_id', $registers->pluck('id'))
            ->get()
            ->groupBy('cash_register_id');

        $corrections = DB::table('till_corrections')
            ->whereIn('cash_register_id', $registers->pluck('id'))
            ->orderBy('id')
            ->get(['cash_register_id', 'currency_code', 'corrected_expected_cash'])
            ->groupBy(fn ($c) => $c->cash_register_id . '|' . strtoupper((string) $c->currency_code));

        $out = collect();
        foreach ($registers as $r) {
            $home = strtoupper((string) ($r->currency_code ?: 'KES'));
            $rows = $balances->get($r->id, collect())->keyBy(fn ($b) => strtoupper($b->currency_code));
            $led  = $ledger->get($r->id, collect())->groupBy(fn ($l) => $l->currency_code ?: $home);
            foreach ($rows->keys()->merge($led->keys())->unique() as $cur) {
                $t = $led->get($cur, collect())->pluck('total', 'transaction_type');
                $sum = fn (string $k) => round((float) ($t[$k] ?? 0), 2);
                $row = $rows->get($cur);
                $opening = $cur === $home ? round((float) $r->opening_balance, 2) : round((float) ($row->opening_balance ?? 0), 2);
                $expected = round($opening + $sum('sale') + $sum('cash_in') - $sum('refund') - $sum('void') - $sum('cash_out'), 2);
                $stated = $row === null ? null : round((float) ($row->expected_cash_at_count ?? $row->expected_cash), 2);
                $last = $corrections->get($r->id . '|' . $cur)?->last();
                if ($last !== null && $last->corrected_expected_cash !== null) {
                    $stated = round((float) $last->corrected_expected_cash, 2);
                }
                $out->push([
                    'register_id'   => $r->id,
                    'register_name' => $r->register_name,
                    'outlet_id'     => $r->outlet_id,
                    'status'        => $r->status,
                    'currency'      => $cur,
                    'opening'       => $opening,
                    'cash_sales'    => $sum('sale'),
                    'cash_in'       => $sum('cash_in'),
                    'refunds'       => $sum('refund'),
                    'voids'         => $sum('void'),
                    'paid_outs'     => $sum('cash_out'),
                    'ledger_expected' => $expected,
                    'stated_expected' => $stated,
                    'restated'      => $last !== null,
                    'ties'          => $stated !== null && abs($stated - $expected) < self::TOLERANCE,
                    'difference'    => $stated === null ? null : round($stated - $expected, 2),
                ]);
            }
        }

        return $out;
    }

    // ── against the engine ───────────────────────────────────────────────────

    /** @return array<string, array{independent: float, engine: float, ties: bool}> */
    public function againstEngine(Carbon $from, Carbon $to): array
    {
        $engine = MetricEngine::scopedTo($this->outletIds);
        $prevFrom = $from->copy()->subDay();  // the previous window is irrelevant here
        $prevTo   = $from->copy()->subDay();

        $sales     = $this->sales($from, $to);
        $collected = $this->collected($from, $to);
        $owed      = $this->owed();

        $eSales     = (float) $engine->revenue($from, $to, $prevFrom, $prevTo)['current'];
        $eCollected = (float) $engine->collected($from, $to, $prevFrom, $prevTo)['current'];
        $eOwed      = (float) $engine->outstandingBalance()['amount'];

        $cmp = fn (float $a, float $b) => ['independent' => $a, 'engine' => $b, 'ties' => abs($a - $b) < self::TOLERANCE];

        return [
            'sales'     => $cmp($sales['kes_total'], $eSales),
            'collected' => $cmp($collected['kes_total'], $eCollected),
            'owed'      => $cmp($owed['owed_kes'], $eOwed),
        ];
    }

    // ── the rules, written out ───────────────────────────────────────────────

    public static function recognised(object $o): bool
    {
        return !in_array($o->status, Order::DEAD_STATUSES, true)
            && (in_array($o->status, Order::RECOGNISED_STATUSES, true)
                || in_array($o->payment_status, Order::SETTLED_PAYMENT_STATUSES, true));
    }

    public static function settled(object $p): bool
    {
        return $p->status === 'paid' && (!$p->requires_approval || $p->approval_status === 'approved');
    }

    /** The row's own stored rate; the live rate only when it carries none; null when there is no rate at all. */
    public static function kes(float $amount, string $currency, $storedRate): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'KES') {
            return round($amount, 2);
        }
        $rate = $storedRate !== null && (float) $storedRate > 0 ? (float) $storedRate : (ReportingCurrency::rates()[$currency] ?? null);

        return $rate === null ? null : round($amount * $rate, 2);
    }
}
