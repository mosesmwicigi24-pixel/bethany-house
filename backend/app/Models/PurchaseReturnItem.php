<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight model for the purchase_return_items table.
 * Used by PurchaseReturn::returnItems() relationship and withCount().
 */
class PurchaseReturnItem extends Model
{
    protected $table = 'purchase_return_items';

    public $timestamps = false;  // table only has created_at
    const CREATED_AT   = 'created_at';
    const UPDATED_AT   = null;

    protected $fillable = [
        'return_id',
        'po_item_id',
        'quantity',
        'reason',
    ];

    protected $casts = [
        'quantity'   => 'float',
        'created_at' => 'datetime',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }
}