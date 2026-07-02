<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_number',
        'order_id',
        'status',
        'return_reason',
        'customer_notes',
        'admin_notes',
        'refund_amount',
        'refund_method',
        'requested_at',
        'approved_at',
        'received_at',
        'refunded_at',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            }
            if (empty($return->requested_at)) {
                $return->requested_at = now();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeRequested($query)
    {
        return $query->where('status', 'requested');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function canBeApproved()
    {
        return $this->status === 'requested';
    }

    public function approve($userId)
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId,
        ]);

        return $this;
    }
}
