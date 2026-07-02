<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'address_type',
        'label',
        'first_name',
        'last_name',
        'company',
        'address_line1',
        'address_line2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'phone',
        'email',
        'latitude',
        'longitude',
        'is_default',
        'delivery_instructions',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_default' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scopes
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeShipping($query)
    {
        return $query->whereIn('address_type', ['shipping', 'both']);
    }

    public function scopeBilling($query)
    {
        return $query->whereIn('address_type', ['billing', 'both']);
    }

    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Accessors
     */
    public function getFullNameAttribute()
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getFullAddressAttribute()
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

    public function getFormattedAddressAttribute()
    {
        $lines = [];
        
        if ($this->company) {
            $lines[] = $this->company;
        }
        
        $lines[] = $this->full_name;
        $lines[] = $this->address_line1;
        
        if ($this->address_line2) {
            $lines[] = $this->address_line2;
        }
        
        $cityLine = $this->city;
        if ($this->state_province) {
            $cityLine .= ', ' . $this->state_province;
        }
        if ($this->postal_code) {
            $cityLine .= ' ' . $this->postal_code;
        }
        $lines[] = $cityLine;
        
        $lines[] = $this->country_code;
        
        if ($this->phone) {
            $lines[] = 'Phone: ' . $this->phone;
        }
        
        return implode("\n", $lines);
    }

    /**
     * Helper methods
     */
    public function setAsDefault()
    {
        // Remove default from other addresses
        if ($this->customer_id) {
            static::where('customer_id', $this->customer_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);
        } elseif ($this->user_id) {
            static::where('user_id', $this->user_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);
        }

        $this->update(['is_default' => true]);
        
        return $this;
    }

    public function canBeUsedForShipping()
    {
        return in_array($this->address_type, ['shipping', 'both']);
    }

    public function canBeUsedForBilling()
    {
        return in_array($this->address_type, ['billing', 'both']);
    }

    public function calculateDistance($latitude, $longitude)
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        // Haversine formula for calculating distance
        $earthRadius = 6371; // km

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($latitude);
        $lonTo = deg2rad($longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
