<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'item_type',
        'product_id',
        'product_variant_id',
        'material_id',
        'description',
        'quantity',
        'quantity_received',
        'unit_price',
        'tax_amount',
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quantity_received' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function grnItems()
    {
        return $this->hasMany(GrnItem::class, 'po_item_id');
    }

    public function getRemainingQuantityAttribute()
    {
        return $this->quantity - $this->quantity_received;
    }

    public function isFullyReceived()
    {
        return $this->quantity_received >= $this->quantity;
    }
}
