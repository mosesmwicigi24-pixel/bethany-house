<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialTransaction extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'material_inventory_id',
        'transaction_type',
        'reference_type',
        'reference_id',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity_change' => 'decimal:2',
        'quantity_before' => 'decimal:2',
        'quantity_after' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function materialInventory()
    {
        return $this->belongsTo(MaterialInventory::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
