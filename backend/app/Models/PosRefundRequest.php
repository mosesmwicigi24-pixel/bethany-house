<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A till refund that has been ASKED for and not yet signed (Phase 4B part 2).
 *
 * Nothing has moved while it is pending: no stock back on the shelf, no cash
 * out of a drawer, no order_returns row. When the approval engine's last band
 * signs, App\Services\Pos\TillReversals writes the refund as a NEW
 * order_returns row and links it here. The sale itself is never edited.
 */
class PosRefundRequest extends Model
{
    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'outlet_id', 'status', 'items', 'refund_amount', 'currency_code',
        'refund_method', 'reason', 'requested_by', 'decided_by', 'decided_at', 'order_return_id',
    ];

    protected $casts = [
        'items'         => 'array',
        'refund_amount' => 'decimal:2',
        'decided_at'    => 'datetime',
    ];

    public function order(): BelongsTo
    {
        // The sale is read across the viewer scope: whoever signs may not be
        // someone who could list it.
        return $this->belongsTo(Order::class)->withoutGlobalScope(Scopes\ViewerScope::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }
}
