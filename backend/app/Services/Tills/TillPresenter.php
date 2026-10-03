<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\TillCorrection;
use App\Models\TillDiscrepancy;
use App\Models\TillReconciliation;
use App\Models\User;

/**
 * One shape for a till, for every viewer — with one rule about who sees what.
 *
 * BLIND COUNT. Until a till is finalized, its operator is never told what the
 * drawer should hold: not expected cash, not the variance, and not the
 * sales totals that add up to it (opening float + cash sales − refunds is
 * expected cash by another name, and total − card − M-Pesa is cash sales).
 * She sees her float, her transaction count, her own count once submitted,
 * and where the till is in its lifecycle. Once a manager has finalized it,
 * she may see how it came out.
 *
 * The verifier, accountant and finance see everything, including the ledger
 * breakdown behind expected cash and, for legacy registers, how far the
 * ledger figure is from the old running column.
 */
final class TillPresenter
{
    public function __construct(private TillService $tills) {}

    public static function stage(CashRegister $r): string
    {
        if ($r->finalized_at !== null) {
            return 'finalized';
        }

        return match ($r->status) {
            'open'    => 'open',
            'counted' => 'awaiting_verification',
            'closed'  => 'closed_unverified',   // closed under the old flow, never verified
            default   => (string) $r->status,
        };
    }

    public static function isBlindFor(CashRegister $r, ?User $viewer): bool
    {
        return $viewer !== null
            && (int) $r->opened_by === (int) $viewer->id
            && $r->finalized_at === null
            && in_array($r->status, ['open', 'counted'], true);
    }

    /** @return array<string,mixed> */
    public function present(CashRegister $r, ?User $viewer, bool $withHistory = false): array
    {
        $r->loadMissing(['outlet:id,name', 'openedBy:id,first_name,last_name', 'closedBy:id,first_name,last_name']);

        $name = fn ($u) => $u ? trim($u->first_name . ' ' . $u->last_name) : null;

        $base = [
            'id'                => $r->id,
            'register_number'   => $r->register_number,
            'register_name'     => $r->register_name,
            'outlet_id'         => $r->outlet_id,
            'outlet_name'       => $r->outlet?->name,
            'currency_code'     => $r->currency_code,
            'status'            => $r->status,
            'stage'             => self::stage($r),
            'legacy'            => $r->lifecycle_version === null,
            'opened_by_id'      => $r->opened_by,
            'opened_by'         => $name($r->openedBy),
            'closed_by'         => $name($r->closedBy),
            'opened_at'         => $r->opened_at?->toIso8601String(),
            'closed_at'         => $r->closed_at?->toIso8601String(),
            'finalized_at'      => $r->finalized_at?->toIso8601String(),
            'opening_cash'      => (float) ($r->opening_balance ?? 0),
            'transaction_count' => (int) ($r->transaction_count ?? 0),
            // Her own count is hers to see; it is not the expected figure.
            'counted_cash'      => $r->status === 'open' ? null : (float) ($r->actual_cash ?? 0),
            'closing_cash'      => $r->status === 'open' ? null : (float) ($r->closing_balance ?? 0),
            'notes'             => $r->opening_notes,
            'closing_notes'     => $r->closing_notes,
        ];

        if (self::isBlindFor($r, $viewer)) {
            return $base + ['blind' => true];
        }

        $verifier = $r->verified_by ? User::find($r->verified_by, ['id', 'first_name', 'last_name']) : null;
        $discrepancy = TillDiscrepancy::where('cash_register_id', $r->id)->first();
        $latestRec = TillReconciliation::where('cash_register_id', $r->id)->orderByDesc('id')->first();

        $full = $base + [
            'blind'                          => false,
            'expected_cash'                  => (float) ($r->expected_cash ?? $r->opening_balance ?? 0),
            'expected_cash_at_count'         => $r->expected_cash_at_count !== null ? (float) $r->expected_cash_at_count : null,
            'expected_cash_running_at_count' => $r->expected_cash_running_at_count !== null ? (float) $r->expected_cash_running_at_count : null,
            'expected_basis_mismatch'        => $this->tills->basisMismatch($r),
            'variance'                       => $r->variance !== null ? (float) $r->variance : null,
            'variance_class'                 => $r->variance_class,
            'variance_reason'                => $r->variance_reason,
            'verification_notes'             => $r->verification_notes,
            'verified_by'                    => $name($verifier),
            'verified_by_id'                 => $r->verified_by,
            'float_vs_previous_close'        => $r->float_vs_previous_close !== null ? (float) $r->float_vs_previous_close : null,
            'previous_register_id'           => $r->previous_register_id,
            'total_sales'                    => (float) ($r->total_sales ?? 0),
            'total_cash_sales'               => (float) ($r->total_cash_sales ?? 0),
            'total_card_sales'               => (float) ($r->total_card_sales ?? 0),
            'total_mpesa_sales'              => (float) ($r->total_mpesa_sales ?? 0),
            'total_refunds'                  => (float) ($r->total_refunds ?? 0),
            'discrepancy'                    => $discrepancy ? $this->discrepancy($discrepancy) : null,
            'reconciliation'                 => $latestRec ? $this->reconciliation($latestRec) : null,
        ];

        if ($withHistory) {
            $full['breakdown'] = $this->tills->ledgerBreakdown($r);
            $full['denomination_count'] = $r->denomination_count;
            $full['reconciliations'] = TillReconciliation::where('cash_register_id', $r->id)
                ->orderByDesc('id')->get()->map(fn ($x) => $this->reconciliation($x))->all();
            $full['corrections'] = TillCorrection::where('cash_register_id', $r->id)
                ->orderBy('id')->get()->map(fn ($x) => $this->correction($x))->all();
        }

        return $full;
    }

    /** @return array<string,mixed> */
    public function discrepancy(TillDiscrepancy $d): array
    {
        return [
            'id'                      => $d->id,
            'amount'                  => (float) $d->amount,
            'direction'               => $d->direction,
            'expected_cash'           => (float) $d->expected_cash,
            'counted_cash'            => (float) $d->counted_cash,
            'expected_basis_mismatch' => $d->expected_basis_mismatch !== null ? (float) $d->expected_basis_mismatch : null,
            'reason'                  => $d->reason,
            'status'                  => $d->status,
            'approval_request_id'     => $d->approval_request_id,
            'created_at'              => $d->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function reconciliation(TillReconciliation $x): array
    {
        $by = $x->reconciled_by ? User::find($x->reconciled_by, ['id', 'first_name', 'last_name']) : null;

        return [
            'id'                       => $x->id,
            'status'                   => $x->status,
            'flagged_for_finance'      => (bool) $x->flagged_for_finance,
            'till_cash_sales'          => (float) $x->till_cash_sales,
            'payments_cash'            => (float) $x->payments_cash,
            'difference'               => (float) $x->difference,
            'missing_from_till_count'  => (int) $x->missing_from_till_count,
            'missing_from_till_amount' => (float) $x->missing_from_till_amount,
            'details'                  => $x->details,
            'notes'                    => $x->notes,
            'reconciled_by'            => $by ? trim($by->first_name . ' ' . $by->last_name) : null,
            'created_at'               => $x->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function correction(TillCorrection $c): array
    {
        $by = $c->opened_by ? User::find($c->opened_by, ['id', 'first_name', 'last_name']) : null;

        return [
            'id'                      => $c->id,
            'cash_register_id'        => $c->cash_register_id,
            'reason'                  => $c->reason,
            'original_actual_cash'    => $c->original_actual_cash !== null ? (float) $c->original_actual_cash : null,
            'original_expected_cash'  => $c->original_expected_cash !== null ? (float) $c->original_expected_cash : null,
            'original_variance'       => $c->original_variance !== null ? (float) $c->original_variance : null,
            'corrected_actual_cash'   => $c->corrected_actual_cash !== null ? (float) $c->corrected_actual_cash : null,
            'corrected_expected_cash' => $c->corrected_expected_cash !== null ? (float) $c->corrected_expected_cash : null,
            'corrected_variance'      => $c->corrected_variance !== null ? (float) $c->corrected_variance : null,
            'status'                  => $c->status,
            'opened_by'               => $by ? trim($by->first_name . ' ' . $by->last_name) : null,
            'created_at'              => $c->created_at?->toIso8601String(),
        ];
    }
}
