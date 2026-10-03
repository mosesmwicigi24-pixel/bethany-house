<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a paid order was before its lines were edited, what it became, who did
 * it and why (Phase 4B: paid orders are never silently edited in place).
 * Written by OrderLineEditor in the same transaction as the edit. Immutable.
 */
class OrderCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'actor_id', 'kind', 'reason', 'amount_paid', 'old_total', 'new_total', 'before', 'after',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'old_total'   => 'decimal:2',
        'new_total'   => 'decimal:2',
        'before'      => 'array',
        'after'       => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('An order correction is a record of history; it never changes.'));
        static::deleting(fn () => throw new \LogicException('An order correction is a record of history; it is not deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
