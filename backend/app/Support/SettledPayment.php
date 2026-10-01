<?php

namespace App\Support;

/**
 * WHEN money counts as collected — one definition for every report
 * (owner delegated the decision, 2026-10-02).
 *
 *   status 'paid', and approved where approval applies
 *
 * The approval workflow usually holds an unapproved payment at 'pending', so
 * status alone was enough — until PaymentApprovalController::uploadProof,
 * which deliberately leaves a payment at 'paid' while it goes back to
 * pending_review ("it only becomes effective after admin approval"). Every
 * report counted it the moment the proof was uploaded. Fourteen queries read
 * paid payments; this is the clause they share, so the fifteenth cannot
 * forget it. Measured on production at the change: 0 rows affected — every
 * approval-flow payment counted as paid had been approved.
 *
 * The overpayment check (cycle 9) and the Collected drill's own definition
 * already said this; the tile and the ledgers now do too.
 */
final class SettledPayment
{
    /** SQL predicate on a payments alias (or bare table name). */
    public static function sql(string $p = 'payments'): string
    {
        return "{$p}.status = 'paid' AND ({$p}.requires_approval IS NOT TRUE OR {$p}.approval_status = 'approved')";
    }

    /** The same rule on a query builder. */
    public static function where($query, string $p = 'payments')
    {
        return $query->whereRaw('(' . self::sql($p) . ')');
    }
}
