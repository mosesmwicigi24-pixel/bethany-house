<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'product_id',
        'product_variant_id',
        'quantity_requested',
        'quantity_received',   // actual DB column (not quantity_transferred)
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_received'  => 'integer',
    ];

    public function transfer()
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}