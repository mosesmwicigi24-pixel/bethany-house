<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipmentTracking extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'shipment_tracking';

    protected $fillable = [
        'shipment_id',
        'status',
        'location',
        'description',
        'event_time',
        'is_public',
        'added_by',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'created_at' => 'datetime',
        'is_public'  => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function shipment()
    {
        return $this->belongsTo(OrderShipment::class, 'shipment_id');
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Attachments uploaded against this specific tracking event.
     * Each carries its own is_public flag - only public ones are exposed
     * on the customer-facing /track/{token} page.
     */
    public function attachments()
    {
        return $this->hasMany(ShipmentAttachment::class, 'attachable_id')
            ->where('attachable_type', 'tracking');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('event_time', 'desc');
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('event_time', 'asc');
    }
}