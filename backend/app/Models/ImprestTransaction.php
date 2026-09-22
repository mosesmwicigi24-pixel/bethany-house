<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of the imprest ledger. Append-only: the database refuses UPDATE and
 * DELETE (trigger), and so does this model — a correction is a new row.
 */
class ImprestTransaction extends Model
{
    public const OPENING            = 'opening';
    public const TOP_UP             = 'top_up';
    public const EXPENSE            = 'expense';
    public const EXPENSE_ADJUSTMENT = 'expense_adjustment';
    public const CASH_RETURNED      = 'cash_returned';
    public const COUNT_ADJUSTMENT   = 'count_adjustment';

    public $timestamps = false;
    protected $guarded = ['id'];

    protected $casts = [
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The imprest ledger is append-only: add a correcting row instead.'));
        static::deleting(fn () => throw new LogicException('The imprest ledger is append-only: add a correcting row instead.'));
    }

    public function account(): BelongsTo { return $this->belongsTo(ImprestAccount::class, 'imprest_account_id'); }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function topupRequest(): BelongsTo { return $this->belongsTo(ImprestTopupRequest::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
