<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's engagement on one messaging channel, synced from Neema.
 * See the create_channel_touchpoints_table migration.
 */
class ChannelTouchpoint extends Model
{
    protected $fillable = [
        'phone', 'channel', 'customer_id',
        'messages', 'inbound', 'first_seen', 'last_seen', 'synced_at',
    ];

    protected $casts = [
        'messages'   => 'integer',
        'inbound'    => 'integer',
        'first_seen' => 'datetime',
        'last_seen'  => 'datetime',
        'synced_at'  => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
