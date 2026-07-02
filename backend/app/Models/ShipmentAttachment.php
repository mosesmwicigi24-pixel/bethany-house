<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single uploaded file attached either directly to a shipment (e.g. a
 * waybill) or to one specific tracking event (e.g. a delivery photo).
 *
 * attachable_type distinguishes which parent it belongs to:
 *   'shipment' → attachable_id references order_shipments.id
 *   'tracking' → attachable_id references shipment_tracking.id
 *
 * is_public controls whether the file is exposed on the public
 * /track/{token} tracking page. Defaults to false - staff must explicitly
 * mark a file as customer-visible.
 */
class ShipmentAttachment extends Model
{
    use HasFactory;

    protected $table = 'shipment_attachments';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'shipment_id',
        'path',
        'original_name',
        'is_public',
        'uploaded_by',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function shipment()
    {
        return $this->belongsTo(OrderShipment::class, 'shipment_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeForShipment($query, int $shipmentId)
    {
        return $query->where('attachable_type', 'shipment')->where('attachable_id', $shipmentId);
    }

    public function scopeForTracking($query, int $trackingId)
    {
        return $query->where('attachable_type', 'tracking')->where('attachable_id', $trackingId);
    }
}