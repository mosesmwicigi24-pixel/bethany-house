<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'variant_name',
        'attributes',
        'weight',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'weight' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Inventory rows with no specific outlet - warehouse/global stock fallback.
     */
    public function warehouseInventoryItems()
    {
        return $this->hasMany(InventoryItem::class)->whereNull('outlet_id');
    }
}