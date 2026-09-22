<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ask → send (super admin) → receive (custodian; the ledger credit happens here). */
class ImprestTopupRequest extends Model
{
    public const PENDING   = 'pending';
    public const SENT      = 'sent';
    public const RECEIVED  = 'received';
    public const DECLINED  = 'declined';
    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'requested_amount'   => 'decimal:2',
        'balance_at_request' => 'decimal:2',
        'sent_amount'        => 'decimal:2',
        'received_amount'    => 'decimal:2',
        'sent_at'            => 'datetime',
        'received_at'        => 'datetime',
        'declined_at'        => 'datetime',
    ];

    public function account(): BelongsTo { return $this->belongsTo(ImprestAccount::class, 'imprest_account_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function decliner(): BelongsTo { return $this->belongsTo(User::class, 'declined_by'); }
}
