<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One band's signature (or the one rejection) on an approval request. */
class ApprovalSignature extends Model
{
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = ['approval_request_id', 'band_order', 'signer_id', 'decision', 'covers', 'reason', 'signed_at'];

    protected $casts = [
        'band_order' => 'integer',
        'covers'     => 'array',
        'signed_at'  => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_id');
    }
}
