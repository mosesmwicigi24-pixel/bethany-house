<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_number',
        'purchase_order_id',
        'supplier_id',
        'return_date',
        'reason',
        'status',
        'credit_amount',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'credit_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->return_number)) {
                $return->return_number = 'PR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Purchase return items (raw table - no dedicated Eloquent model).
     * Declared so withCount() works correctly.
     */
    public function returnItems()
    {
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}