<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The cash actually in the box, against the book balance. The super admin approves any adjustment. */
class ImprestCashCount extends Model
{
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $casts = [
        'counted_amount' => 'decimal:2',
        'book_balance'   => 'decimal:2',
        'variance'       => 'decimal:2',
        'decided_at'     => 'datetime',
    ];

    public function account(): BelongsTo { return $this->belongsTo(ImprestAccount::class, 'imprest_account_id'); }
    public function counter(): BelongsTo { return $this->belongsTo(User::class, 'counted_by'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
