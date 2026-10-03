<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of one event's approval thresholds (Phase 3B). Rows are never
 * edited: a change inserts a new set with a later effective_from, read through
 * App\Services\Approvals\ThresholdRepository.
 */
class ApprovalThreshold extends Model
{
    protected $fillable = [
        'event', 'band_order', 'up_to_kes', 'approver_permission', 'effective_from', 'created_by',
    ];

    protected $casts = [
        'band_order'     => 'integer',
        'up_to_kes'      => 'decimal:2',
        'effective_from' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
