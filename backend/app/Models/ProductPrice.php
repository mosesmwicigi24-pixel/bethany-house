<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'currency_code',
        'regular_price',
        'sale_price',
        'cost_price',
        'sale_start_date',
        'sale_end_date',
    ];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'sale_start_date' => 'datetime',
        'sale_end_date' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getEffectivePrice()
    {
        if ($this->sale_price && $this->isOnSale()) {
            return $this->sale_price;
        }
        return $this->regular_price;
    }

    public function isOnSale()
    {
        $now = now();
        
        if (!$this->sale_price) {
            return false;
        }

        if ($this->sale_start_date && $now->lt($this->sale_start_date)) {
            return false;
        }

        if ($this->sale_end_date && $now->gt($this->sale_end_date)) {
            return false;
        }

        return true;
    }
}
