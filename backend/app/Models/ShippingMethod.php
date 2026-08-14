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

    /**
     * What this method costs for an order of $orderTotal (goods only, before
     * shipping). The single definition of the rate: ShippingController::rates()
     * quotes from it and OrderController::checkout() charges from it, so the
     * price a customer is shown and the price they are billed cannot diverge.
     *
     * `percentage` reads flat_rate as a percentage of the order — the column
     * doubles as the rate for both types, which is what the admin screens
     * (ShippingRates / ShippingMethods) write. It used to return 0 here, so a
     * percentage method quietly shipped free.
     *
     * $weight is accepted for the `weight_based` type the rates admin screen can
     * set but nothing prices yet; such a method falls through to flat_rate.
     */
    public function calculateCost($orderTotal, $weight = null): float
    {
        return match ($this->cost_type) {
            'free'       => 0.0,
            'flat_rate'  => (float) $this->flat_rate,
            'percentage' => round((float) $orderTotal * (float) $this->flat_rate / 100, 2),
            default      => (float) $this->flat_rate,
        };
    }

    /**
     * Whether this method may be offered — and therefore charged — for an order
     * of $orderTotal. min_order_amount is a floor: below it the method is not on
     * the menu at all.
     */
    public function isAvailableForOrder($orderTotal): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->min_order_amount && $orderTotal < (float) $this->min_order_amount) {
            return false;
        }

        return true;
    }
}
