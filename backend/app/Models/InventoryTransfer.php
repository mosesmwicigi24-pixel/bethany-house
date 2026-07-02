<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_number',
        'from_outlet_id',
        'to_outlet_id',
        'status',
        'transfer_date',  // NOT NULL in DB
        'notes',
        'requested_by',
        'approved_by',
        'completed_by',
        'requested_at',
        'approved_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'requested_at'  => 'datetime',
        'approved_at'   => 'datetime',
        'completed_at'  => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($transfer) {
            if (empty($transfer->transfer_number)) {
                $transfer->transfer_number = 'TRF-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }

    public function fromOutlet()   { return $this->belongsTo(Outlet::class, 'from_outlet_id'); }
    public function toOutlet()     { return $this->belongsTo(Outlet::class, 'to_outlet_id'); }
    public function items()        { return $this->hasMany(InventoryTransferItem::class, 'transfer_id'); }
    public function requestedBy()  { return $this->belongsTo(User::class, 'requested_by'); }
    public function approvedBy()   { return $this->belongsTo(User::class, 'approved_by'); }
    public function completedBy()  { return $this->belongsTo(User::class, 'completed_by'); }

    public function scopePending($query)   { return $query->where('status', 'pending'); }
    public function scopeApproved($query)  { return $query->where('status', 'approved'); }
    public function scopeCompleted($query) { return $query->where('status', 'completed'); }
}