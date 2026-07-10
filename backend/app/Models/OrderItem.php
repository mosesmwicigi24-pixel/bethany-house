<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'variant_name',
        'sku',
        'quantity',
        'unit_price',
        'original_price',      // Catalogue price before any manual adjustment
        'price_adjusted',      // True when unit_price was manually overridden upward
        'discount_amount',
        'tax_amount',
        'total_price',
        'production_order_id',
        'inventory_item_id',   // the exact finished-goods row this line drew from
        'notes',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'unit_price'      => 'decimal:2',
        'original_price'  => 'decimal:2',
        'price_adjusted'  => 'boolean',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_price'     => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function getSubtotalAttribute()
    {
        return $this->unit_price * $this->quantity;
    }

    /**
     * How much the price was adjusted above catalogue on this line.
     * Returns 0 if no adjustment was made.
     */
    public function getPriceUpliftAttribute(): float
    {
        if (!$this->price_adjusted || is_null($this->original_price)) {
            return 0.0;
        }
        return max(0, (float) $this->unit_price - (float) $this->original_price);
    }

    /**
     * Total revenue uplift from the price adjustment on this line.
     * (unit_price - original_price) * quantity
     */
    public function getPriceUpliftTotalAttribute(): float
    {
        return $this->getPriceUpliftAttribute() * (int) $this->quantity;
    }

    public function canBeReturned()
    {
        return $this->order->isCompleted() &&
               $this->quantity > $this->returnItems()->sum('quantity');
    }
}