<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'inventory_item_id',
        'transaction_type',
        'reference_type',
        'reference_id',
        'reference_number',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'notes',
        'reason_code',
        'status',
        'approval_notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after'  => 'integer',
        'unit_cost'       => 'decimal:2',
        'created_at'      => 'datetime',
        'approved_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeSales($query)
    {
        return $query->where('transaction_type', 'sale');
    }

    public function scopePurchases($query)
    {
        return $query->where('transaction_type', 'purchase');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}