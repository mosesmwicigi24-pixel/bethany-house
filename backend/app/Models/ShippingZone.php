<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function countries()
    {
        return $this->belongsToMany(Country::class, 'shipping_zone_countries', 'shipping_zone_id', 'country_code', 'id', 'code');
    }

    public function methods()
    {
        return $this->hasMany(ShippingMethod::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function hasCountry($countryCode)
    {
        return $this->countries()->where('code', $countryCode)->exists();
    }
}
