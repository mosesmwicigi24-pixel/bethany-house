<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day's message-volume delta on one channel for one phone, derived from
 * Neema's cumulative rollup by channels:sync-touchpoints. See the
 * create_channel_touchpoint_daily_table migration for why this exists.
 */
class ChannelTouchpointDaily extends Model
{
    protected $table = 'channel_touchpoint_daily';

    protected $fillable = [
        'date', 'channel', 'phone', 'messages', 'inbound',
    ];

    protected $casts = [
        'date'     => 'date',
        'messages' => 'integer',
        'inbound'  => 'integer',
    ];
}
