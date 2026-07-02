<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderApproval extends Model
{
    protected $table = 'production_order_approvals';

    protected $fillable = [
        'production_order_id',
        'gate',
        'approved_by',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}