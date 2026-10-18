<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A non-zero variance found when a till was finalized (Phase 4B).
 *
 * |amount| ≤ 100 is `logged` (reason recorded, nothing to approve). Above that
 * it is `awaiting_approval`: part 2 hands it to the Phase 3B ApprovalEngine
 * (approval_request_id) and records the outcome in the workflow columns. The
 * facts — till, amount, expected, counted, reason — never change; on Postgres a
 * trigger refuses it.
 */
class TillDiscrepancy extends Model
{
    public const LOGGED_LIMIT = 100.0;

    public const STATUS_LOGGED = 'logged';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    protected $fillable = [
        'cash_register_id', 'outlet_id', 'operator_id', 'raised_by', 'amount', 'direction',
        'currency_code', 'expected_cash', 'counted_cash', 'expected_basis_mismatch', 'reason',
        'status', 'approval_request_id', 'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'amount'                  => 'decimal:2',
        'expected_cash'           => 'decimal:2',
        'counted_cash'            => 'decimal:2',
        'expected_basis_mismatch' => 'decimal:2',
        'resolved_at'             => 'datetime',
    ];

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * The band is KES 100, whatever currency the drawer row is in: a foreign
     * variance is converted at the REPORTING rate (ReportingCurrency). A
     * currency with no rate cannot be banded, so it is held for approval —
     * never guessed through.
     */
    public static function statusFor(float $variance, string $currency = 'KES'): string
    {
        $kes = \App\Support\ReportingCurrency::toKes(abs($variance), $currency);
        if ($kes === null) {
            return self::STATUS_AWAITING_APPROVAL;
        }

        return $kes > self::LOGGED_LIMIT ? self::STATUS_AWAITING_APPROVAL : self::STATUS_LOGGED;
    }
}
