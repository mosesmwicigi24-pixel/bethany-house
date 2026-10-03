<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\TillCorrection;
use App\Models\TillDiscrepancy;
use App\Models\TillReconciliation;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
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

    /**
     * Expected cash from the drawer's ledger:
     *   opening float + cash sales + cash in − cash refunds − cash voids − paid-outs.
     *
     * Amounts are taken as absolute values: recordCashLedger writes refunds,
     * voids and paid-outs positive, CashRegister's own helpers write them
     * negative, and both shapes exist in production.
     *
     * @return array{opening_float: float, cash_sales: float, cash_in: float, refunds: float, voids: float, paid_outs: float, expected: float}
     */
    public function ledgerBreakdown(CashRegister $register): array
    {
        $totals = DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', 'cash'))
            ->groupBy('transaction_type')
            ->selectRaw('transaction_type, COALESCE(SUM(ABS(amount)), 0) AS total')
            ->pluck('total', 'transaction_type');

        $sum = fn (string $type): float => round((float) ($totals[$type] ?? 0), 2);

        $opening  = round((float) $register->opening_balance, 2);
        $sales    = $sum('sale');
        $cashIn   = $sum('cash_in');
        $refunds  = $sum('refund');
        $voids    = $sum('void');
        $paidOuts = $sum('cash_out');

        return [
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
     * Step 2a — the operator's blind count. The till leaves `open` (it takes no
     * more sales), the expected figure is computed and frozen beside her count.
     * Nothing about the expected figure is returned from here to the caller's
     * response; the controller hands back TillPresenter's blind view.
     */
    public function submitCount(CashRegister $register, User $operator, float $counted, ?array $denominations, ?string $notes): CashRegister
    {
        return DB::transaction(function () use ($register, $operator, $counted, $denominations, $notes) {
            /** @var CashRegister $locked */
            $locked = CashRegister::whereKey($register->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ConflictHttpException('This till has already been counted.');
            }

            $breakdown = $this->ledgerBreakdown($locked);
            $expected  = $breakdown['expected'];
            $variance  = round($counted - $expected, 2);
            $running   = round((float) ($locked->expected_cash ?? $locked->opening_balance ?? 0), 2);

            $locked->update([
                'status'                         => 'counted',
                'actual_cash'                    => $counted,
                'closing_balance'                => $counted,
                'denomination_count'             => $denominations,
                'closing_notes'                  => $notes,
                'closed_by'                      => $operator->id,
                'closed_at'                      => now(),
                'expected_cash_at_count'         => $expected,
                'expected_cash_running_at_count' => $running,
                'variance'                       => $variance,
                'variance_class'                 => self::classify($variance),
            ]);

            ActivityLogService::log('till_counted', $locked, [
                'register_id'        => $locked->id,
                'outlet_id'          => $locked->outlet_id,
                'counted_cash'       => $counted,
                'expected_cash'      => $expected,
                'expected_running'   => $running,
                'variance'           => $variance,
                'legacy'             => $locked->lifecycle_version === null,
                'breakdown'          => $breakdown,
            ], "Till #{$locked->id} counted (blind)", $operator);

            return $locked->fresh();
        });
    }

    /**
     * Step 2b — the outlet manager verifies the count and finalizes the till.
     * From here its money is locked (CashRegister + the database trigger).
     *
     * @return array{0: CashRegister, 1: ?TillDiscrepancy}
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

            $variance = round((float) $locked->variance, 2);

            $locked->update([
                'status'             => 'closed',
                'verified_by'        => $verifier->id,
                'finalized_at'       => now(),
                'variance_reason'    => $reason,
                'verification_notes' => $notes,
            ]);

            $discrepancy = null;
            if (abs($variance) >= 0.005) {
                $discrepancy = TillDiscrepancy::create([
                    'cash_register_id'        => $locked->id,
                    'outlet_id'               => $locked->outlet_id,
                    'operator_id'             => $locked->opened_by,
                    'raised_by'               => $verifier->id,
                    'amount'                  => $variance,
                    'direction'               => $variance > 0 ? 'over' : 'short',
                    'currency_code'           => $locked->currency_code ?? 'KES',
                    'expected_cash'           => $locked->expected_cash_at_count,
                    'counted_cash'            => $locked->actual_cash,
                    'expected_basis_mismatch' => $this->basisMismatch($locked),
                    'reason'                  => (string) $reason,
                    'status'                  => TillDiscrepancy::statusFor($variance),
                ]);
            }

            ActivityLogService::log('till_finalized', $locked, [
                'register_id'    => $locked->id,
                'outlet_id'      => $locked->outlet_id,
                'operator_id'    => $locked->opened_by,
                'variance'       => $variance,
                'variance_class' => $locked->variance_class,
                'discrepancy_id' => $discrepancy?->id,
                'discrepancy'    => $discrepancy?->status,
            ], "Till #{$locked->id} verified and finalized", $verifier);

            return [$locked->fresh(), $discrepancy];
        });
    }

    /** Ledger expected − running expected, when they disagree; null when they agree. */
    public function basisMismatch(CashRegister $register): ?float
    {
        if ($register->expected_cash_at_count === null || $register->expected_cash_running_at_count === null) {
            return null;
        }
        $d = round((float) $register->expected_cash_at_count - (float) $register->expected_cash_running_at_count, 2);

        return abs($d) < 0.005 ? null : $d;
    }

    /**
     * Step 4 — the accountant's independent check of a finalized till against
     * the payments ledger. Two questions, both answered from payments, not
     * from the till:
     *
     *   1. For every order the till's ledger says it took cash for, did the
     *      payments ledger record the same cash, during the shift?
     *   2. Did the operator take cash for one of her own orders during the
     *      shift that no till's ledger recorded at all?
     *
     * Any difference is a mismatch, flagged for finance. Append-only: a second
     * reconciliation is a new row.
     */
    public function reconcile(CashRegister $register, User $accountant, ?string $notes): TillReconciliation
    {
        if ($register->finalized_at === null) {
            throw new ConflictHttpException('Only a verified, finalized till can be reconciled.');
        }

        $from = $register->opened_at;
        $to   = $register->closed_at ?? $register->finalized_at;

        $cashCodes = DB::table('payment_methods')->where('type', 'cash')->pluck('code')->push('cash')->unique()->values()->all();

        // 1. Per order: what the till took vs what payments recorded.
        $tillByOrder = DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->where('transaction_type', 'sale')
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(ABS(amount)) AS total')
            ->pluck('total', 'order_id')
            ->map(fn ($v) => round((float) $v, 2));

        $paymentsByOrder = $tillByOrder->isEmpty() ? collect() : DB::table('payments')
            ->whereIn('order_id', $tillByOrder->keys())
            ->whereIn('payment_method', $cashCodes)
            ->whereIn('status', self::TAKEN_STATUSES)
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

        // 2. Cash the operator took for her own orders that reached no till.
        $missing = collect();
        if ($register->opened_by) {
            $missing = DB::table('payments as p')
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
                ->get(['p.id as payment_id', 'p.order_id', 'p.amount'])
                ->map(fn ($r) => ['payment_id' => (int) $r->payment_id, 'order_id' => (int) $r->order_id, 'amount' => round((float) $r->amount, 2)]);
        }

        $isMismatch = $mismatches !== [] || $missing->isNotEmpty();

        $rec = TillReconciliation::create([
            'cash_register_id'         => $register->id,
            'reconciled_by'            => $accountant->id,
            'counted_cash'             => $register->actual_cash,
            'expected_cash'            => $register->expected_cash_at_count,
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
            'status'            => $rec->status,
            'difference'        => (float) $rec->difference,
            'missing_count'     => $rec->missing_from_till_count,
        ], "Till #{$register->id} reconciled: {$rec->status}", $accountant);

        return $rec;
    }

    /**
     * Step 5 — finance's correction of a finalized till. A new record that
     * points at the original; the original is never reopened or changed.
     *
     * @param array{reason: string, corrected_actual_cash?: ?float, corrected_expected_cash?: ?float} $data
     */
    public function openCorrection(CashRegister $register, User $finance, array $data): TillCorrection
    {
        if ($register->finalized_at === null) {
            throw new ConflictHttpException('Only a finalized till takes a correction. Until then it is still being counted and verified.');
        }

        $origActual   = $register->actual_cash !== null ? round((float) $register->actual_cash, 2) : null;
        $origExpected = $register->expected_cash_at_count !== null ? round((float) $register->expected_cash_at_count, 2) : null;
        $newActual    = array_key_exists('corrected_actual_cash', $data) && $data['corrected_actual_cash'] !== null
            ? round((float) $data['corrected_actual_cash'], 2) : $origActual;
        $newExpected  = array_key_exists('corrected_expected_cash', $data) && $data['corrected_expected_cash'] !== null
            ? round((float) $data['corrected_expected_cash'], 2) : $origExpected;

        $correction = TillCorrection::create([
            'cash_register_id'        => $register->id,
            'opened_by'               => $finance->id,
            'reason'                  => $data['reason'],
            'original_actual_cash'    => $origActual,
            'original_expected_cash'  => $origExpected,
            'original_variance'       => $register->variance,
            'corrected_actual_cash'   => $data['corrected_actual_cash'] ?? null,
            'corrected_expected_cash' => $data['corrected_expected_cash'] ?? null,
            'corrected_variance'      => ($newActual !== null && $newExpected !== null) ? round($newActual - $newExpected, 2) : null,
            'status'                  => 'recorded',
        ]);

        ActivityLogService::log('till_correction_opened', $register, [
            'register_id'   => $register->id,
            'correction_id' => $correction->id,
            'reason'        => $correction->reason,
            'from_variance' => $correction->original_variance,
            'to_variance'   => $correction->corrected_variance,
        ], "Correction #{$correction->id} opened against till #{$register->id}", $finance);

        return $correction;
    }
}
