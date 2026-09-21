<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to take a file out of the hub. See App\Http\Middleware\DownloadGate. */
class DownloadRequest extends Model
{
    public const PENDING    = 'pending';
    public const APPROVED   = 'approved';
    public const DENIED     = 'denied';
    public const EXPIRED    = 'expired';
    public const CANCELLED  = 'cancelled';
    public const DOWNLOADED = 'downloaded';
    public const AUTO       = 'auto';
    /** Attempted while the gate enforces; waiting for the requester to ask for approval. */
    public const HELD       = 'held';
    /** The route itself refused or errored (no file left). */
    public const FAILED     = 'failed';

    protected $guarded = ['id'];

    protected $casts = [
        'payload'           => 'array',
        'auto_approved'     => 'boolean',
        'shadow'            => 'boolean',
        'decided_at'        => 'datetime',
        'token_expires_at'  => 'datetime',
        'downloaded_at'     => 'datetime',
        'owner_notified_at' => 'datetime',
    ];

    // The token hash never leaves the server.
    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
