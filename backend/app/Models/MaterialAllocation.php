<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_order_id',
        'material_id',
        'quantity_required',
        'quantity_allocated',
        'quantity_used',
        'quantity_returned',
        'allocated_at',
        'allocated_by',
    ];

    protected $casts = [
        'quantity_required' => 'decimal:2',
        'quantity_allocated' => 'decimal:2',
        'quantity_used' => 'decimal:2',
        'quantity_returned' => 'decimal:2',
        'allocated_at' => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function allocatedBy()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function getRemainingQuantityAttribute()
    {
        return $this->quantity_allocated - $this->quantity_used - $this->quantity_returned;
    }
}
