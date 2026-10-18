<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\TillCorrection;
use App\Models\TillDiscrepancy;
use App\Models\TillReconciliation;
use App\Models\User;
use App\Support\ReportingCurrency;

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
 *
 * EVERY CURRENCY APART (currency plan Step 2). The top-level money fields are
 * the till's HOME currency, as they always were. `balances` carries one entry
 * per currency the till holds (home first), each with its own float, expected,
 * count and variance, under the same blind rule. `kes_summary` states the
 * whole till in shillings at the REPORTING rate — and names any currency it
 * could not convert rather than leaving it out silently.
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

        $name  = fn ($u) => $u ? trim($u->first_name . ' ' . $u->last_name) : null;
        $blind = self::isBlindFor($r, $viewer);
        $rows  = $this->balanceRows($r);

        $base = [
            'id'                => $r->id,
            'register_number'   => $r->register_number,
            'register_name'     => $r->register_name,
            'outlet_id'         => $r->outlet_id,
            'outlet_name'       => $r->outlet?->name,
            'currency_code'     => $r->homeCurrency(),
            'status'            => $r->status,
            'stage'             => self::stage($r),
            'legacy'            => $r->lifecycle_version === null,
            'opened_by_id'      => $r->opened_by,
            'opened_by'         => $name($r->openedBy),
            'closed_by'         => $name($r->closedBy),
            'opened_at'         => $r->opened_at?->toIso8601String(),
            'closed_at'         => $r->closed_at?->toIso8601String(),
            'finalized_at'      => $r->finalized_at?->toIso8601String(),
            // Ran past its trading day (the morning check, 2026-10-15): counted
            // on a later day than it opened, or still open after midnight. The
            // verifier should know the drawer sat unattended overnight.
            'left_open_overnight' => $r->opened_at !== null
                && $r->opened_at->toDateString() < ($r->closed_at ?? now())->toDateString(),
            'opening_cash'      => (float) ($r->opening_balance ?? 0),
            'transaction_count' => (int) ($r->transaction_count ?? 0),
            // Her own count is hers to see; it is not the expected figure.
            'counted_cash'      => $r->status === 'open' ? null : (float) ($r->actual_cash ?? 0),
            'closing_cash'      => $r->status === 'open' ? null : (float) ($r->closing_balance ?? 0),
            'notes'             => $r->opening_notes,
            'closing_notes'     => $r->closing_notes,
            'balances'          => $rows->map(fn (CashRegisterBalance $b) => $this->balance($r, $b, $blind, $withHistory))->values()->all(),
        ];

        if ($blind) {
            return $base + ['blind' => true];
        }

        $verifier      = $r->verified_by ? User::find($r->verified_by, ['id', 'first_name', 'last_name']) : null;
        $discrepancies = TillDiscrepancy::where('cash_register_id', $r->id)->orderBy('id')->get();
        $homeDisc      = $discrepancies->first(fn ($d) => strtoupper((string) $d->currency_code) === $r->homeCurrency());
        $latestRecs    = TillReconciliation::where('cash_register_id', $r->id)->orderByDesc('id')->get()
            ->unique(fn ($x) => strtoupper((string) ($x->currency_code ?: $r->homeCurrency())))->values();
        $homeRec       = $latestRecs->first(fn ($x) => strtoupper((string) ($x->currency_code ?: $r->homeCurrency())) === $r->homeCurrency());

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
            // The home currency's, as before; every currency's under `discrepancies`.
            'discrepancy'                    => $homeDisc ? $this->discrepancy($homeDisc) : null,
            'discrepancies'                  => $discrepancies->map(fn ($d) => $this->discrepancy($d))->values()->all(),
            'reconciliation'                 => $homeRec ? $this->reconciliation($homeRec) : null,
            'latest_reconciliations'         => $latestRecs->map(fn ($x) => $this->reconciliation($x))->values()->all(),
            'kes_summary'                    => $this->kesSummary($r, $rows),
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

    /**
     * The till's balance rows, home first. A till with no rows yet (opened
     * before Step 2, or built straight into the table) is shown through its
     * own columns as a single home row — nothing is written while reading.
     *
     * @return \Illuminate\Support\Collection<int, CashRegisterBalance>
     */
    private function balanceRows(CashRegister $r)
    {
        $rows = CashRegisterBalance::where('cash_register_id', $r->id)
            ->orderByDesc('is_home')->orderBy('currency_code')->get();
        if ($rows->isNotEmpty()) {
            return $rows;
        }

        return collect([new CashRegisterBalance([
            'cash_register_id'               => $r->id,
            'currency_code'                  => $r->homeCurrency(),
            'is_home'                        => true,
            'opening_balance'                => $r->opening_balance ?? 0,
            'expected_cash'                  => $r->expected_cash ?? $r->opening_balance ?? 0,
            'actual_cash'                    => $r->status === 'open' ? null : $r->actual_cash,
            'expected_cash_at_count'         => $r->expected_cash_at_count,
            'expected_cash_running_at_count' => $r->expected_cash_running_at_count,
            'variance'                       => $r->variance,
            'variance_class'                 => $r->variance_class,
            'denomination_count'             => $r->denomination_count,
            'total_sales'                    => $r->total_sales ?? 0,
            'total_cash_sales'               => $r->total_cash_sales ?? 0,
            'total_card_sales'               => $r->total_card_sales ?? 0,
            'total_mpesa_sales'              => $r->total_mpesa_sales ?? 0,
            'total_refunds'                  => $r->total_refunds ?? 0,
            'transaction_count'              => $r->transaction_count ?? 0,
        ])]);
    }

    /** @return array<string,mixed> */
    private function balance(CashRegister $r, CashRegisterBalance $b, bool $blind, bool $withHistory): array
    {
        $base = [
            'currency_code' => strtoupper((string) $b->currency_code),
            'is_home'       => (bool) $b->is_home,
            'opening_cash'  => (float) ($b->opening_balance ?? 0),
            'counted_cash'  => $r->status === 'open' || $b->actual_cash === null ? null : (float) $b->actual_cash,
        ];
        if ($blind) {
            return $base;
        }

        $out = $base + [
            'expected_cash'                  => (float) ($b->expected_cash ?? 0),
            'expected_cash_at_count'         => $b->expected_cash_at_count !== null ? (float) $b->expected_cash_at_count : null,
            'expected_cash_running_at_count' => $b->expected_cash_running_at_count !== null ? (float) $b->expected_cash_running_at_count : null,
            'expected_basis_mismatch'        => $this->tills->basisMismatchOf($b->expected_cash_at_count, $b->expected_cash_running_at_count),
            'variance'                       => $b->variance !== null ? (float) $b->variance : null,
            'variance_class'                 => $b->variance_class,
            'total_sales'                    => (float) ($b->total_sales ?? 0),
            'total_cash_sales'               => (float) ($b->total_cash_sales ?? 0),
            'total_card_sales'               => (float) ($b->total_card_sales ?? 0),
            'total_mpesa_sales'              => (float) ($b->total_mpesa_sales ?? 0),
            'total_refunds'                  => (float) ($b->total_refunds ?? 0),
            'transaction_count'              => (int) ($b->transaction_count ?? 0),
        ];
        if ($withHistory) {
            $out['breakdown']          = $this->tills->ledgerBreakdown($r, $b->currency_code);
            $out['denomination_count'] = $b->denomination_count;
        }

        return $out;
    }

    /**
     * The whole till in shillings at the reporting rate: expected, counted and
     * variance summed across the currencies that have a rate. Any currency
     * without one is named in `unconverted` and left out of the sums.
     *
     * @param  \Illuminate\Support\Collection<int, CashRegisterBalance>  $rows
     * @return array{basis: string, expected: float, counted: ?float, variance: ?float, unconverted: list<string>}
     */
    private function kesSummary(CashRegister $r, $rows): array
    {
        $expected = 0.0;
        $counted  = 0.0;
        $variance = 0.0;
        $anyCount = false;
        $unconverted = [];

        foreach ($rows as $b) {
            $code = strtoupper((string) $b->currency_code);
            $exp  = (float) ($b->expected_cash_at_count ?? $b->expected_cash ?? 0);
            $kes  = ReportingCurrency::toKes($exp, $code);
            if ($kes === null) {
                $unconverted[] = $code;
                continue;
            }
            $expected += $kes;
            if ($b->actual_cash !== null && $r->status !== 'open') {
                $anyCount = true;
                $counted  += (float) ReportingCurrency::toKes((float) $b->actual_cash, $code);
                $variance += (float) ReportingCurrency::toKes((float) ($b->variance ?? 0), $code);
            }
        }

        return [
            'basis'       => 'reporting rate',
            'expected'    => round($expected, 2),
            'counted'     => $anyCount ? round($counted, 2) : null,
            'variance'    => $anyCount ? round($variance, 2) : null,
            'unconverted' => $unconverted,
        ];
    }

    /** @return array<string,mixed> */
    public function discrepancy(TillDiscrepancy $d): array
    {
        return [
            'id'                      => $d->id,
            'currency_code'           => strtoupper((string) ($d->currency_code ?: 'KES')),
            'amount'                  => (float) $d->amount,
            'amount_kes'              => ReportingCurrency::toKes((float) $d->amount, (string) ($d->currency_code ?: 'KES')),
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
            'currency_code'            => strtoupper((string) ($x->currency_code ?: 'KES')),
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
            'currency_code'           => strtoupper((string) ($c->currency_code ?: 'KES')),
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
