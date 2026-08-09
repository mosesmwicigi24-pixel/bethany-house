<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual win-back contact attempt (WhatsApp / call / other) logged from
 * the Win-back tab. phone is canonical E.164 digits (no '+') via
 * App\Support\Phone — the replenishment_pings convention — so recovery
 * attribution can bridge to orders via normalize_phone.
 */
class WinBackOutreach extends Model
{
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_CALL     = 'call';
    public const CHANNEL_OTHER    = 'other';

    protected $table = 'win_back_outreach';

    protected $fillable = [
        'customer_id',
        'phone',
        'name',
        'channel',
        'contacted_by',
        'revenue_365_at_contact',
        'days_quiet_at_contact',
    ];

    protected $casts = [
        'revenue_365_at_contact' => 'decimal:2',
        'days_quiet_at_contact'  => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }
}
