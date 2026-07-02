<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'subject', 'preview_text', 'html_body', 'plain_body',
        'from_name', 'from_email', 'reply_to', 'status',
        'audience', 'audience_filters', 'scheduled_at', 'sent_at',
        'recipient_count', 'sent_count', 'opened_count', 'clicked_count',
        'bounced_count', 'unsubscribed_count', 'created_by',
    ];

    protected $casts = [
        'audience_filters'  => 'array',
        'scheduled_at'      => 'datetime',
        'sent_at'           => 'datetime',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeDraft($query)    { return $query->where('status', 'draft'); }
    public function scopeSent($query)     { return $query->where('status', 'sent'); }
    public function scopeScheduled($query){ return $query->where('status', 'scheduled'); }

    public function getOpenRateAttribute(): float
    {
        if (!$this->sent_count) return 0;
        return round($this->opened_count / $this->sent_count * 100, 1);
    }

    public function getClickRateAttribute(): float
    {
        if (!$this->sent_count) return 0;
        return round($this->clicked_count / $this->sent_count * 100, 1);
    }
}