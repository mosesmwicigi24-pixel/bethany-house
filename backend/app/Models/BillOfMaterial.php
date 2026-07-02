<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillOfMaterial extends Model
{
    use HasFactory;

    protected $table = 'bills_of_materials';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'version',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function items()
    {
        return $this->hasMany(BomItem::class, 'bom_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTotalCost()
    {
        return $this->items->sum(function ($item) {
            return $item->quantity * ($item->material->cost_per_unit ?? 0);
        });
    }
}