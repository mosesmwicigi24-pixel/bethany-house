<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\TillCorrection;
use App\Models\TillDiscrepancy;
use App\Models\TillReconciliation;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The till lifecycle (role hardening Phase 4B, part 1):
 *
 *   open → counted (operator's blind count) → finalized (outlet manager)
 *        → reconciled (accountant, against payments) → corrected (finance, linked record)
 *
 * Every figure is the server's. Expected cash comes from the drawer's own
 * ledger (cash_register_transactions), is frozen the moment the operator
 * submits her count, and is never shown to her before the till is finalized
 * (TillPresenter). A variance is classified and recorded, never absorbed.
 *
 * ONE TILL, EVERY CURRENCY APART (currency plan Step 2, owner 2026-10-18).
 * A till keeps a balance row per currency it has touched (TillDrawer). Each
 * step below runs per row: the operator counts each currency, each gets its
 * own expected figure and variance, a discrepancy is raised per currency,
 * the accountant reconciles each against payments in that currency. Nothing
 * is added across currencies; the KES view of a foreign variance is stated
 * at the reporting rate and only where one exists. The register's own money
 * columns remain the HOME currency's figures.
 *
 * Authorization (who may call which step) is the controller's; this class
 * enforces only what the state of the till allows.
 *
 * Part 2 seams: TillDiscrepancy rows with status awaiting_approval and
 * TillCorrection rows are what the Phase 3B ApprovalEngine will be handed
 * (approval_request_id on both). No approval logic lives here.
 */
class TillService
{
    /** Payment statuses meaning "money was taken" (later voids/refunds included — the cash still came in). */
    private const TAKEN_STATUSES = ['paid', 'completed', 'approved', 'voided', 'refunded', 'partially_refunded'];

    public function __construct(private TillDrawer $drawer) {}

    /**
     * Expected cash from the drawer's ledger, for ONE currency (the till's own
     * when none is named):
     *   opening float + cash sales + cash in − cash refunds − cash voids − paid-outs.
     *
     * Amounts are taken as absolute values: recordCashLedger writes refunds,
     * voids and paid-outs positive, CashRegister's own helpers write them
     * negative, and both shapes exist in production. A ledger row with no
     * currency (written before Step 2) is in the till's own currency.
     *
     * @return array{currency_code: string, opening_float: float, cash_sales: float, cash_in: float, refunds: float, voids: float, paid_outs: float, expected: float}
     */
    public function ledgerBreakdown(CashRegister $register, ?string $currency = null): array
    {
        $home     = $register->homeCurrency();
        $currency = strtoupper($currency ?: $home);

        $totals = DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', 'cash'))
            ->whereRaw('UPPER(COALESCE(currency_code, ?)) = ?', [$home, $currency])
            ->groupBy('transaction_type')
            ->selectRaw('transaction_type, COALESCE(SUM(ABS(amount)), 0) AS total')
            ->pluck('total', 'transaction_type');

        $sum = fn (string $type): float => round((float) ($totals[$type] ?? 0), 2);

        if ($currency === $home) {
            $opening = round((float) $register->opening_balance, 2);
        } else {
            $opening = round((float) CashRegisterBalance::where('cash_register_id', $register->id)
                ->where('currency_code', $currency)->value('opening_balance'), 2);
        }
        $sales    = $sum('sale');
        $cashIn   = $sum('cash_in');
        $refunds  = $sum('refund');
        $voids    = $sum('void');
        $paidOuts = $sum('cash_out');

        return [
            'currency_code' => $currency,
            'opening_float' => $opening,
            'cash_sales'    => $sales,
            'cash_in'       => $cashIn,
            'refunds'       => $refunds,
            'voids'         => $voids,
            'paid_outs'     => $paidOuts,
            'expected'      => round($opening + $sales + $cashIn - $refunds - $voids - $paidOuts, 2),
        ];
    }

    public static function classify(float $variance): string
    {
        if (abs($variance) < 0.005) {
            return 'balanced';
        }

        return $variance > 0 ? 'over' : 'short';
    }

    /**
     * The till's balance rows, home first. A till opened before Step 2 (or
     * created straight into the table by a test) has none: its home row is
     * made from the register's own columns, so every till has at least one.
     *
     * @return Collection<int, CashRegisterBalance>
     */
    public function balances(CashRegister $register, bool $lock = false): Collection
    {
        $this->drawer->balanceFor($register, $register->homeCurrency(), $lock);

        return $this->drawer->balances($register);
    }

    /** Has any currency of this till a non-zero variance (once counted)? */
    public function hasVariance(CashRegister $register): bool
    {
        if ($register->status === 'open') {
            return false;
        }
        $rows = CashRegisterBalance::where('cash_register_id', $register->id)->get();
        if ($rows->isEmpty()) {
            return abs((float) $register->variance) >= 0.005;
        }

        return $rows->contains(fn ($b) => $b->variance !== null && abs((float) $b->variance) >= 0.005);
    }

    /**
     * The opener's float against the last count at this outlet. Logged on the
     * new till; the previous till is never touched.
     *
     * @return array{previous_register_id: ?int, float_vs_previous_close: ?float, previous_close: ?float}
     */
    public function compareFloat(int $outletId, float $float, ?int $excludeId = null): array
    {
        $previous = CashRegister::where('outlet_id', $outletId)
            ->whereIn('status', ['counted', 'closed'])
            ->whereNotNull('closed_at')
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first(['id', 'actual_cash']);

        if (!$previous) {
            return ['previous_register_id' => null, 'float_vs_previous_close' => null, 'previous_close' => null];
        }

        return [
            'previous_register_id'    => (int) $previous->id,
            'float_vs_previous_close' => round($float - (float) $previous->actual_cash, 2),
            'previous_close'          => round((float) $previous->actual_cash, 2),
        ];
    }

    /**
     * Step 2a — the operator's blind count, one figure per currency the till
     * holds. $counted and $denominations are the HOME currency; $counts carries
     * the others as [{currency_code, counted_cash, denomination_count?}]. A
     * currency the till holds that she did not count is refused — unless the
     * drawer never had any of it, which counts as zero. The till leaves `open`
     * (it takes no more sales); each currency's expected figure is computed
     * and frozen beside her count. Nothing about the expected figures is
     * returned from here to the caller's response; the controller hands back
     * TillPresenter's blind view.
     *
     * @param  list<array{currency_code: string, counted_cash: float|int|string, denomination_count?: ?array}>  $counts
     */
    public function submitCount(CashRegister $register, User $operator, float $counted, ?array $denominations, ?string $notes, array $counts = []): CashRegister
    {
        return DB::transaction(function () use ($register, $operator, $counted, $denominations, $notes, $counts) {
            /** @var CashRegister $locked */
            $locked = CashRegister::whereKey($register->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ConflictHttpException('This till has already been counted.');
            }

            $home = $locked->homeCurrency();
            $rows = $this->balances($locked, true)->keyBy('currency_code');

            $typed = [$home => ['counted' => round($counted, 2), 'denominations' => $denominations]];
            foreach ($counts as $i => $c) {
                $code = strtoupper(trim((string) ($c['currency_code'] ?? '')));
                if ($code === '' || $code === $home) {
                    continue;
                }
                if (!$rows->has($code)) {
                    throw ValidationException::withMessages(["counts.{$i}.currency_code" => [
                        "This till holds no {$code}; there is nothing to count in it.",
                    ]]);
                }
                $typed[$code] = ['counted' => round((float) ($c['counted_cash'] ?? 0), 2), 'denominations' => $c['denomination_count'] ?? null];
            }

            $details = [];
            foreach ($rows as $code => $row) {
                $breakdown = $this->ledgerBreakdown($locked, $code);
                $expected  = $breakdown['expected'];
                $running   = $row->is_home
                    ? round((float) ($locked->expected_cash ?? $locked->opening_balance ?? 0), 2)
                    : round((float) $row->expected_cash, 2);

                if (!isset($typed[$code])) {
                    if (abs($expected) >= 0.005 || abs($running) >= 0.005) {
                        throw ValidationException::withMessages(['counts' => [
                            "Count the {$code} in the drawer too: this till took {$code} cash.",
                        ]]);
                    }
                    $typed[$code] = ['counted' => 0.0, 'denominations' => null];
                }

                $countedHere = $typed[$code]['counted'];
                $variance    = round($countedHere - $expected, 2);

                $row->update([
                    'actual_cash'                    => $countedHere,
                    'denomination_count'             => $typed[$code]['denominations'],
                    'expected_cash_at_count'         => $expected,
                    'expected_cash_running_at_count' => $running,
                    'variance'                       => $variance,
                    'variance_class'                 => self::classify($variance),
                ]);

                $details[$code] = [
                    'counted_cash'     => $countedHere,
                    'expected_cash'    => $expected,
                    'expected_running' => $running,
                    'variance'         => $variance,
                    'breakdown'        => $breakdown,
                ];
            }

            // The register's own columns: the home currency, exactly as before.
            $h = $details[$home];
            $locked->update([
                'status'                         => 'counted',
                'actual_cash'                    => $h['counted_cash'],
                'closing_balance'                => $h['counted_cash'],
                'denomination_count'             => $denominations,
                'closing_notes'                  => $notes,
                'closed_by'                      => $operator->id,
                'closed_at'                      => now(),
                'expected_cash_at_count'         => $h['expected_cash'],
                'expected_cash_running_at_count' => $h['expected_running'],
                'variance'                       => $h['variance'],
                'variance_class'                 => self::classify($h['variance']),
            ]);

            ActivityLogService::log('till_counted', $locked, [
                'register_id'        => $locked->id,
                'outlet_id'          => $locked->outlet_id,
                'currency_code'      => $home,
                'counted_cash'       => $h['counted_cash'],
                'expected_cash'      => $h['expected_cash'],
                'expected_running'   => $h['expected_running'],
                'variance'           => $h['variance'],
                'legacy'             => $locked->lifecycle_version === null,
                'breakdown'          => $h['breakdown'],
                'currencies'         => $details,
            ], "Till #{$locked->id} counted (blind)", $operator);

            return $locked->fresh();
        });
    }

    /**
     * Step 2b — the outlet manager verifies the count and finalizes the till.
     * From here its money is locked (CashRegister + the database trigger). A
     * discrepancy is raised for every currency whose count differs from its
     * expected figure; the approval band is applied in shillings at the
     * reporting rate (TillDiscrepancy::statusFor).
     *
     * @return array{0: CashRegister, 1: list<TillDiscrepancy>}
     */
    public function finalize(CashRegister $register, User $verifier, ?string $reason, ?string $notes): array
    {
        return DB::transaction(function () use ($register, $verifier, $reason, $notes) {
            /** @var CashRegister $locked */
            $locked = CashRegister::whereKey($register->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'counted' || $locked->finalized_at !== null) {
                throw new ConflictHttpException($locked->status === 'open'
                    ? 'This till is still open. The cashier submits her count first.'
                    : 'This till is not awaiting verification.');
            }

            $rows = $this->balances($locked, true);

            // Discrepancies are raised BEFORE the lock lands: a balance row of a
            // finalized till can no longer be read for writing on Postgres.
            $discrepancies = [];
            foreach ($rows as $row) {
                if ($row->actual_cash === null) {
                    continue;
                }
                $variance = round((float) $row->variance, 2);
                if (abs($variance) < 0.005) {
                    continue;
                }
                $discrepancies[] = TillDiscrepancy::create([
                    'cash_register_id'        => $locked->id,
                    'outlet_id'               => $locked->outlet_id,
                    'operator_id'             => $locked->opened_by,
                    'raised_by'               => $verifier->id,
                    'amount'                  => $variance,
                    'direction'               => $variance > 0 ? 'over' : 'short',
                    'currency_code'           => $row->currency_code,
                    'expected_cash'           => $row->expected_cash_at_count,
                    'counted_cash'            => $row->actual_cash,
                    'expected_basis_mismatch' => $this->basisMismatchOf(
                        $row->expected_cash_at_count, $row->expected_cash_running_at_count,
                    ),
                    'reason'                  => (string) $reason,
                    'status'                  => TillDiscrepancy::statusFor($variance, $row->currency_code),
                ]);
            }

            $locked->update([
                'status'             => 'closed',
                'verified_by'        => $verifier->id,
                'finalized_at'       => now(),
                'variance_reason'    => $reason,
                'verification_notes' => $notes,
            ]);

            ActivityLogService::log('till_finalized', $locked, [
                'register_id'    => $locked->id,
                'outlet_id'      => $locked->outlet_id,
                'operator_id'    => $locked->opened_by,
                'variance'       => round((float) $locked->variance, 2),
                'variance_class' => $locked->variance_class,
                'discrepancies'  => array_map(fn (TillDiscrepancy $d) => [
                    'id' => $d->id, 'currency_code' => $d->currency_code, 'amount' => (float) $d->amount, 'status' => $d->status,
                ], $discrepancies),
            ], "Till #{$locked->id} verified and finalized", $verifier);

            return [$locked->fresh(), $discrepancies];
        });
    }

    /** Ledger expected − running expected, when they disagree; null when they agree. (Home currency.) */
    public function basisMismatch(CashRegister $register): ?float
    {
        return $this->basisMismatchOf($register->expected_cash_at_count, $register->expected_cash_running_at_count);
    }

    public function basisMismatchOf($ledgerExpected, $runningExpected): ?float
    {
        if ($ledgerExpected === null || $runningExpected === null) {
            return null;
        }
        $d = round((float) $ledgerExpected - (float) $runningExpected, 2);

        return abs($d) < 0.005 ? null : $d;
    }

    /**
     * Step 4 — the accountant's independent check of a finalized till against
     * the payments ledger, one row per currency the till moved money in. Two
     * questions, both answered from payments, not from the till:
     *
     *   1. For every order the till's ledger says it took cash for, did the
     *      payments ledger record the same cash, in that currency, during the shift?
     *   2. Did the operator take cash for one of her own orders during the
     *      shift that no till's ledger recorded at all?
     *
     * A payment in dollars is never set against shillings taken: each
     * currency is reconciled on its own. Any difference is a mismatch,
     * flagged for finance. Append-only: a second reconciliation is a new row.
     *
     * @return Collection<int, TillReconciliation> home currency first
     */
    public function reconcile(CashRegister $register, User $accountant, ?string $notes): Collection
    {
        if ($register->finalized_at === null) {
            throw new ConflictHttpException('Only a verified, finalized till can be reconciled.');
        }

        $home = $register->homeCurrency();
        $from = $register->opened_at;
        $to   = $register->closed_at ?? $register->finalized_at;

        $cashCodes = DB::table('payment_methods')->where('type', 'cash')->pluck('code')->push('cash')->unique()->values()->all();

        // 1. Per currency and order: what the till took. The home code is a
        // literal, not a binding: Postgres must see the same expression in the
        // SELECT and the GROUP BY, and two bindings are two expressions.
        $homeLit  = "'" . preg_replace('/[^A-Z]/', '', $home) . "'";
        $tillRows = DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->where('transaction_type', 'sale')
            ->whereNotNull('order_id')
            ->groupByRaw("UPPER(COALESCE(currency_code, {$homeLit})), order_id")
            ->selectRaw("UPPER(COALESCE(currency_code, {$homeLit})) AS currency_code, order_id, SUM(ABS(amount)) AS total")
            ->get();
        $tillByCurrency = $tillRows->groupBy('currency_code')
            ->map(fn ($g) => $g->mapWithKeys(fn ($r) => [(int) $r->order_id => round((float) $r->total, 2)]));

        // 2. Cash the operator took for her own orders that reached no till, by currency.
        $missingByCurrency = collect();
        if ($register->opened_by) {
            $missingByCurrency = DB::table('payments as p')
                ->join('orders as o', 'o.id', '=', 'p.order_id')
                ->where('o.outlet_id', $register->outlet_id)
                ->where('o.created_by', $register->opened_by)
                ->whereIn('p.payment_method', $cashCodes)
                ->whereIn('p.status', self::TAKEN_STATUSES)
                ->whereBetween('p.created_at', [$from, $to])
                ->whereNotExists(function ($q) {
                    $q->from('cash_register_transactions as t')
                        ->whereColumn('t.order_id', 'p.order_id')
                        ->where('t.transaction_type', 'sale');
                })
                ->selectRaw('p.id as payment_id, p.order_id, p.amount, UPPER(COALESCE(p.currency_code, o.currency_code, ?)) AS currency_code', [$home])
                ->get()
                ->groupBy('currency_code')
                ->map(fn ($g) => $g->map(fn ($r) => [
                    'payment_id' => (int) $r->payment_id, 'order_id' => (int) $r->order_id, 'amount' => round((float) $r->amount, 2),
                ])->values());
        }

        $balanceRows = CashRegisterBalance::where('cash_register_id', $register->id)->get()->keyBy('currency_code');
        $currencies  = collect([$home])
            ->merge($tillByCurrency->keys())
            ->merge($missingByCurrency->keys())
            ->merge($balanceRows->keys())
            ->unique()->values();

        $out = collect();
        foreach ($currencies as $currency) {
            $tillByOrder = $tillByCurrency->get($currency, collect());

            $paymentsByOrder = $tillByOrder->isEmpty() ? collect() : DB::table('payments')
                ->whereIn('order_id', $tillByOrder->keys())
                ->whereIn('payment_method', $cashCodes)
                ->whereIn('status', self::TAKEN_STATUSES)
                ->whereRaw('UPPER(COALESCE(currency_code, ?)) = ?', [$home, $currency])
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('order_id')
                ->selectRaw('order_id, SUM(amount) AS total')
                ->pluck('total', 'order_id')
                ->map(fn ($v) => round((float) $v, 2));

            $mismatches = [];
            foreach ($tillByOrder as $orderId => $tillAmount) {
                $paid = (float) ($paymentsByOrder[$orderId] ?? 0);
                if (abs($tillAmount - $paid) >= 0.005) {
                    $mismatches[] = ['order_id' => (int) $orderId, 'till' => $tillAmount, 'payments' => round($paid, 2)];
                }
            }

            $tillCash     = round((float) $tillByOrder->sum(), 2);
            $paymentsCash = round((float) $paymentsByOrder->sum(), 2);
            $missing      = $missingByCurrency->get($currency, collect());
            $isMismatch   = $mismatches !== [] || $missing->isNotEmpty();

            $row = $balanceRows->get($currency);
            $counted  = $currency === $home ? $register->actual_cash : $row?->actual_cash;
            $expected = $currency === $home ? $register->expected_cash_at_count : $row?->expected_cash_at_count;

            $rec = TillReconciliation::create([
                'cash_register_id'         => $register->id,
                'currency_code'            => $currency,
                'reconciled_by'            => $accountant->id,
                'counted_cash'             => $counted,
                'expected_cash'            => $expected,
                'till_cash_sales'          => $tillCash,
                'payments_cash'            => $paymentsCash,
                'difference'               => round($tillCash - $paymentsCash, 2),
                'missing_from_till_count'  => $missing->count(),
                'missing_from_till_amount' => round((float) $missing->sum('amount'), 2),
                'status'                   => $isMismatch ? 'mismatch' : 'matched',
                'flagged_for_finance'      => $isMismatch,
                'details'                  => ['order_mismatches' => $mismatches, 'missing_from_till' => $missing->values()->all()],
                'notes'                    => $notes,
            ]);

            ActivityLogService::log($isMismatch ? 'till_reconciliation_mismatch' : 'till_reconciled', $register, [
                'register_id'       => $register->id,
                'reconciliation_id' => $rec->id,
                'currency_code'     => $currency,
                'status'            => $rec->status,
                'difference'        => (float) $rec->difference,
                'missing_count'     => $rec->missing_from_till_count,
            ], "Till #{$register->id} reconciled ({$currency}): {$rec->status}", $accountant);

            $out->push($rec);
        }

        return $out;
    }

    /**
     * Step 5 — finance's correction of a finalized till, for one of its
     * currencies (the till's own when none is named). A new record that
     * points at the original; the original is never reopened or changed.
     *
     * @param array{reason: string, currency_code?: ?string, corrected_actual_cash?: ?float, corrected_expected_cash?: ?float} $data
     */
    public function openCorrection(CashRegister $register, User $finance, array $data): TillCorrection
    {
        if ($register->finalized_at === null) {
            throw new ConflictHttpException('Only a finalized till takes a correction. Until then it is still being counted and verified.');
        }

        $home     = $register->homeCurrency();
        $currency = strtoupper(trim((string) ($data['currency_code'] ?? '')) ?: $home);

        if ($currency === $home) {
            $origActual   = $register->actual_cash;
            $origExpected = $register->expected_cash_at_count;
            $origVariance = $register->variance;
        } else {
            $row = CashRegisterBalance::where('cash_register_id', $register->id)->where('currency_code', $currency)->first();
            if (!$row) {
                throw ValidationException::withMessages(['currency_code' => ["This till holds no {$currency}."]]);
            }
            $origActual   = $row->actual_cash;
            $origExpected = $row->expected_cash_at_count;
            $origVariance = $row->variance;
        }

        $origActual   = $origActual !== null ? round((float) $origActual, 2) : null;
        $origExpected = $origExpected !== null ? round((float) $origExpected, 2) : null;
        $newActual    = array_key_exists('corrected_actual_cash', $data) && $data['corrected_actual_cash'] !== null
            ? round((float) $data['corrected_actual_cash'], 2) : $origActual;
        $newExpected  = array_key_exists('corrected_expected_cash', $data) && $data['corrected_expected_cash'] !== null
            ? round((float) $data['corrected_expected_cash'], 2) : $origExpected;

        $correction = TillCorrection::create([
            'cash_register_id'        => $register->id,
            'currency_code'           => $currency,
            'opened_by'               => $finance->id,
            'reason'                  => $data['reason'],
            'original_actual_cash'    => $origActual,
            'original_expected_cash'  => $origExpected,
            'original_variance'       => $origVariance,
            'corrected_actual_cash'   => $data['corrected_actual_cash'] ?? null,
            'corrected_expected_cash' => $data['corrected_expected_cash'] ?? null,
            'corrected_variance'      => ($newActual !== null && $newExpected !== null) ? round($newActual - $newExpected, 2) : null,
            'status'                  => 'recorded',
        ]);

        ActivityLogService::log('till_correction_opened', $register, [
            'register_id'   => $register->id,
            'correction_id' => $correction->id,
            'currency_code' => $currency,
            'reason'        => $correction->reason,
            'from_variance' => $correction->original_variance,
            'to_variance'   => $correction->corrected_variance,
        ], "Correction #{$correction->id} opened against till #{$register->id}", $finance);

        return $correction;
    }
}
