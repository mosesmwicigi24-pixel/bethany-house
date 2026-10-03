<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One submission of a record for approval (Phase 3B). See
 * App\Services\Approvals\ApprovalEngine for the rules.
 */
class ApprovalRequest extends Model
{
    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    /** Hours a request waits before it goes back to the maker (never auto-approved). */
    public const TTL_HOURS = 72;

    protected $fillable = [
        'event', 'approvable_type', 'approvable_id', 'version', 'maker_id', 'counterparty',
        'amount', 'currency_code', 'amount_kes', 'basis_kes', 'value_unknown', 'bands', 'ladder',
        'current_band', 'thresholds_effective_from', 'status', 'fingerprint', 'payload',
        'expires_at', 'decided_at', 'decided_by', 'rejected_reason', 'supersedes_id',
    ];

    protected $casts = [
        'version'                   => 'integer',
        'amount'                    => 'decimal:2',
        'amount_kes'                => 'decimal:2',
        'basis_kes'                 => 'decimal:2',
        'value_unknown'             => 'boolean',
        'bands'                     => 'array',
        'ladder'                    => 'array',
        'payload'                   => 'array',
        'current_band'              => 'integer',
        'thresholds_effective_from' => 'datetime',
        'expires_at'                => 'datetime',
        'decided_at'                => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ApprovalSignature::class)->orderBy('band_order');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The snapshotted band currently awaiting a signature, or null. */
    public function currentBandDef(): ?array
    {
        foreach ($this->bands ?? [] as $band) {
            if ((int) $band['order'] === (int) $this->current_band) {
                return $band;
            }
        }

        return null;
    }

    /** The band after the current one in this request's snapshot, or null. */
    public function nextBandDef(): ?array
    {
        $seen = false;
        foreach ($this->bands ?? [] as $band) {
            if ($seen) {
                return $band;
            }
            $seen = (int) $band['order'] === (int) $this->current_band;
        }

        return null;
    }
}
