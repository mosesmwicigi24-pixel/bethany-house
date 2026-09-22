<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A petty-cash float held by a custodian. All money moves through App\Services\ImprestService. */
class ImprestAccount extends Model
{
    protected $guarded = ['id', 'balance'];

    protected $casts = [
        'float_amount'            => 'decimal:2',
        'balance'                 => 'decimal:2',
        'is_active'               => 'boolean',
        'low_balance_percent'     => 'integer',
        'low_balance_notified_at' => 'datetime',
    ];

    public function custodian(): BelongsTo { return $this->belongsTo(User::class, 'custodian_id'); }
    public function outlet(): BelongsTo { return $this->belongsTo(Outlet::class); }
    public function transactions(): HasMany { return $this->hasMany(ImprestTransaction::class); }
    public function topupRequests(): HasMany { return $this->hasMany(ImprestTopupRequest::class); }

    /** KES below which the custodian is told to ask for a top-up. */
    public function lowBalanceThreshold(): float
    {
        return round((float) $this->float_amount * $this->low_balance_percent / 100, 2);
    }

    public function isLow(): bool
    {
        return (float) $this->balance < $this->lowBalanceThreshold();
    }
}
