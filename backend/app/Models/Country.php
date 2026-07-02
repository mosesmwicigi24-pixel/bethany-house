<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'phone_code',
        'flag',
        'region',
        'subregion',
        'default_currency_code',
        'is_active',
        'is_shipping_enabled',
        'free_shipping_threshold',
        'standard_shipping_cost',
        'express_shipping_cost',
        'estimated_delivery_days',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'is_shipping_enabled'     => 'boolean',
        'free_shipping_threshold' => 'decimal:2',
        'standard_shipping_cost'  => 'decimal:2',
        'express_shipping_cost'   => 'decimal:2',
    ];

    public function defaultCurrency()
    {
        return $this->belongsTo(Currency::class, 'default_currency_code', 'code');
    }

    public function taxRates()
    {
        return $this->hasMany(TaxRate::class, 'country_code', 'code');
    }

    public function shippingZones()
    {
        return $this->belongsToMany(ShippingZone::class, 'shipping_zone_countries', 'country_code', 'shipping_zone_id', 'code');
    }

    public function scopeShippingEnabled($query)
    {
        return $query->where('is_shipping_enabled', true);
    }
}
