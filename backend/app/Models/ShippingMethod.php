<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id',
        'name',
        'description',
        'delivery_time',
        'cost_type',
        'flat_rate',
        'min_order_amount',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'flat_rate' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function shippingZone()
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function calculateCost($orderTotal, $weight = null)
    {
        switch ($this->cost_type) {
            case 'flat_rate':
                return $this->flat_rate;
            case 'free':
                return 0;
            case 'percentage':
                // Could be extended for percentage-based shipping
                return 0;
            default:
                return $this->flat_rate;
        }
    }

    public function isAvailableForOrder($orderTotal)
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->min_order_amount && $orderTotal < $this->min_order_amount) {
            return false;
        }

        return true;
    }
}
