<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'shipment_number',
        'carrier',
        'tracking_number',
        'tracking_token',
        'carrier_tracking_url',
        'tracking_url',
        'status',
        'shipped_at',
        'delivered_at',
        'estimated_delivery_date',
        'notes',
        'shipped_from_outlet_id',
        'delivered_to',
        'delivery_signature',
        'delivery_notes',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'shipped_at'              => 'datetime',
        'delivered_at'            => 'datetime',
        'estimated_delivery_date' => 'date',
        'cancelled_at'            => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shipment) {
            if (empty($shipment->shipment_number)) {
                $shipment->shipment_number = 'SHP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            }
            // Always generate a public tracking token
            if (empty($shipment->tracking_token)) {
                $shipment->tracking_token = Str::uuid()->toString();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function tracking()
    {
        return $this->hasMany(ShipmentTracking::class, 'shipment_id');
    }

    /**
     * Attachments uploaded directly against this shipment (e.g. a waybill),
     * as opposed to attachments on a specific tracking event. Each carries
     * its own is_public flag - only public ones are exposed on the
     * customer-facing /track/{token} page.
     */
    public function attachments()
    {
        return $this->hasMany(ShipmentAttachment::class, 'attachable_id')
            ->where('attachable_type', 'shipment');
    }

    /** Only attachments visible to customers */
    public function publicAttachments()
    {
        return $this->attachments()->where('is_public', true);
    }

    /** Only events visible to customers */
    public function publicTracking()
    {
        return $this->hasMany(ShipmentTracking::class, 'shipment_id')
            ->where('is_public', true)
            ->orderBy('event_time', 'asc');
    }

    public function scopeShipped($query)
    {
        return $query->whereNotNull('shipped_at');
    }

    public function scopeDelivered($query)
    {
        return $query->whereNotNull('delivered_at');
    }

    public function scopeInTransit($query)
    {
        return $query->whereNotNull('shipped_at')->whereNull('delivered_at');
    }

    public function isShipped(): bool
    {
        return !is_null($this->shipped_at);
    }

    public function isDelivered(): bool
    {
        return !is_null($this->delivered_at);
    }

    public function addTrackingEvent(
        string $status,
        ?string $location = null,
        ?string $description = null,
        $eventTime = null,
        bool $isPublic = true,
        ?int $addedBy = null,
    ): ShipmentTracking {
        return $this->tracking()->create([
            'status'      => $status,
            'location'    => $location,
            'description' => $description,
            'event_time'  => $eventTime ?? now(),
            'is_public'   => $isPublic,
            'added_by'    => $addedBy,
        ]);
    }

    /** The public-facing tracking URL path (used to share with customers) */
    public function trackingPageUrl(): string
    {
        return config('app.frontend_url', '') . '/track/' . $this->tracking_token;
    }
}