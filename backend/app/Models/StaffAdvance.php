<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An advance or loan to a staff member, repaid through payroll (People PR 7). */
class StaffAdvance extends Model
{
    public const KINDS    = ['advance', 'loan'];
    public const STATUSES = ['requested', 'approved', 'paid', 'settled', 'declined', 'cancelled'];

    protected $fillable = ['user_id', 'kind', 'amount', 'instalment', 'balance', 'reason', 'status', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
        'paid_by', 'paid_at', 'payment_method', 'payment_reference', 'settled_at', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'instalment' => 'decimal:2', 'balance' => 'decimal:2', 'requested_at' => 'datetime', 'decided_at' => 'datetime', 'paid_at' => 'datetime', 'settled_at' => 'datetime'];

    public function user()       { return $this->belongsTo(User::class); }
    public function repayments() { return $this->hasMany(AdvanceRepayment::class); }
    public function decidedBy()  { return $this->belongsTo(User::class, 'decided_by'); }

    /** Rebuild the balance from the rows; the column is a cache of this. */
    public function rebuiltBalance(): float
    {
        return round((float) $this->amount - (float) $this->repayments()->sum('amount'), 2);
    }
}
