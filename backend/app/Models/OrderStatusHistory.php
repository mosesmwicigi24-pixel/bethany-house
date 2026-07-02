<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'notes',
        'notified_customer',
        'created_by',
    ];

    protected $casts = [
        'notified_customer' => 'boolean',
        'created_at'        => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}