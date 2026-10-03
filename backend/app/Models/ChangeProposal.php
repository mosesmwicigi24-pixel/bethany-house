<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a money-relevant value (Phase 3C) — applied at once inside the
 * maker's band, or waiting for the approval engine's signatures. Together the
 * rows for a value are its history. See App\Services\Approvals\ProposalService.
 */
class ChangeProposal extends Model
{
    public const PENDING   = 'pending';     // waiting for signatures
    public const SCHEDULED = 'scheduled';   // signed; takes effect at effective_from
    public const APPLIED   = 'applied';     // the live value now
    public const REJECTED  = 'rejected';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::PENDING, self::SCHEDULED];

    protected $fillable = [
        'event', 'subject_type', 'subject_id', 'subject_label', 'changeset', 'measures',
        'effective_from', 'status', 'direct', 'maker_id', 'note', 'applied_at', 'applied_by',
    ];

    protected $casts = [
        'subject_id'     => 'integer',
        'changeset'      => 'array',
        'measures'       => 'array',
        'effective_from' => 'datetime',
        'applied_at'     => 'datetime',
        'direct'         => 'boolean',
    ];

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** {field: new} */
    public function newValues(): array
    {
        return array_map(fn ($c) => $c['new'] ?? null, $this->changeset ?? []);
    }

    /** {field: old} */
    public function oldValues(): array
    {
        return array_map(fn ($c) => $c['old'] ?? null, $this->changeset ?? []);
    }
}
