<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The accountant's next-day check of a finalized till against the payments
 * ledger (Phase 4B). Append-only: a second look is a second row; on Postgres
 * UPDATE and DELETE are refused by the audit trail's trigger function.
 */
class TillReconciliation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cash_register_id', 'reconciled_by', 'counted_cash', 'expected_cash', 'till_cash_sales',
        'payments_cash', 'difference', 'missing_from_till_count', 'missing_from_till_amount',
        'status', 'flagged_for_finance', 'details', 'notes',
    ];

    protected $casts = [
        'counted_cash'             => 'decimal:2',
        'expected_cash'            => 'decimal:2',
        'till_cash_sales'          => 'decimal:2',
        'payments_cash'            => 'decimal:2',
        'difference'               => 'decimal:2',
        'missing_from_till_amount' => 'decimal:2',
        'missing_from_till_count'  => 'integer',
        'flagged_for_finance'      => 'boolean',
        'details'                  => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A till reconciliation is append-only.'));
        static::deleting(fn () => throw new \LogicException('A till reconciliation is append-only.'));
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
