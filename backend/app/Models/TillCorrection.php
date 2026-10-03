<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Finance's correction of a finalized till (Phase 4B). Nobody reopens a
 * finalized till: the correction is a new record that points at the original,
 * carries the reason and both sets of figures, and leaves the original exactly
 * as it was counted. Only the workflow columns (status, approval_request_id)
 * may move — part 2 routes corrections above the discrepancy band for approval.
 */
class TillCorrection extends Model
{
    public const IMMUTABLE = [
        'cash_register_id', 'opened_by', 'reason', 'original_actual_cash', 'original_expected_cash',
        'original_variance', 'corrected_actual_cash', 'corrected_expected_cash', 'corrected_variance',
    ];

    protected $fillable = [
        'cash_register_id', 'opened_by', 'reason', 'original_actual_cash', 'original_expected_cash',
        'original_variance', 'corrected_actual_cash', 'corrected_expected_cash', 'corrected_variance',
        'status', 'approval_request_id',
    ];

    protected $casts = [
        'original_actual_cash'    => 'decimal:2',
        'original_expected_cash'  => 'decimal:2',
        'original_variance'       => 'decimal:2',
        'corrected_actual_cash'   => 'decimal:2',
        'corrected_expected_cash' => 'decimal:2',
        'corrected_variance'      => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            if ($c->isDirty(self::IMMUTABLE)) {
                throw new \LogicException('A till correction\'s figures and reason never change; open another correction.');
            }
        });
        static::deleting(fn () => throw new \LogicException('A till correction cannot be deleted.'));
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
