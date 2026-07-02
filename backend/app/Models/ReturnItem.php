<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
        'reason',
        'condition',
        'restock',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'restock' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function return()
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
