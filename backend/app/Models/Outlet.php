<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'outlet_type',
        'email',
        'phone',
        'address_line1',
        'address_line2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'is_active',
        'is_pickup_location',
        'operating_hours',
        'geofence_radius_meters',
    ];

    protected $casts = [
        'latitude'                => 'decimal:8',
        'longitude'               => 'decimal:8',
        'is_active'               => 'boolean',
        'is_pickup_location'      => 'boolean',
        'operating_hours'         => 'array',
        'geofence_radius_meters'  => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Users assigned to this outlet (via outlet_user pivot).
     * Replaces the old 'role_user' pivot which was incorrect.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'outlet_user')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    /**
     * The primary/default user assigned to this outlet (e.g. manager).
     */
    public function primaryUser(): ?\App\Models\User
    {
        return $this->users()
                    ->wherePivot('is_primary', true)
                    ->first();
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function materialInventory()
    {
        return $this->hasMany(MaterialInventory::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function pickupOrders()
    {
        return $this->hasMany(Order::class, 'pickup_outlet_id');
    }

    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePickupLocations($query)
    {
        return $query->where('is_pickup_location', true);
    }

    public function scopeStores($query)
    {
        return $query->where('outlet_type', 'store');
    }

    public function scopeWarehouses($query)
    {
        return $query->where('outlet_type', 'warehouse');
    }

    /**
     * Production workshops - a workshop is just an Outlet with this type,
     * so it reuses outlet_user staff assignment, lat/lng, and the geofence
     * config for free (used by the Time Clock feature).
     */
    public function scopeWorkshops($query)
    {
        return $query->where('outlet_type', 'workshop');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state_province,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    public function getTotalInventoryValue()
    {
        return $this->inventoryItems()
            ->join('product_prices', function ($join) {
                $join->on('inventory_items.product_id', '=', 'product_prices.product_id')
                     ->whereColumn('inventory_items.product_variant_id', 'product_prices.product_variant_id');
            })
            ->sum(\DB::raw('inventory_items.quantity_on_hand * product_prices.cost_price'));
    }

    public function isOpenNow(): bool
    {
        if (!$this->operating_hours) {
            return false;
        }

        $dayOfWeek   = strtolower(now()->format('l'));
        $currentTime = now()->format('H:i');

        if (!isset($this->operating_hours[$dayOfWeek])) {
            return false;
        }

        $hours = $this->operating_hours[$dayOfWeek];

        if (!isset($hours['open'], $hours['close'])) {
            return false;
        }

        return $currentTime >= $hours['open'] && $currentTime <= $hours['close'];
    }
}