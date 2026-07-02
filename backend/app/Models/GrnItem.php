<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrnItem extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'grn_id',
        'po_item_id',
        'quantity_received',
        'quantity_rejected',
        'condition',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:2',
        'quantity_rejected' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function goodsReceivedNote()
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }

    public function getAcceptedQuantityAttribute()
    {
        return $this->quantity_received - $this->quantity_rejected;
    }
}
