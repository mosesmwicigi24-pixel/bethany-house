<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Relationships
     */
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
        return $query->where('address_type', 'shipping');
    }

    public function scopeBilling($query)
    {
        return $query->where('address_type', 'billing');
    }

    /**
     * Helper methods
     */
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

    public function getFullNameAttribute()
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
