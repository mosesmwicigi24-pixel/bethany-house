<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderAssignee extends Model
{
    protected $table = 'production_order_assignees';

    protected $fillable = [
        'production_order_id',
        'user_id',
        'role_in_order',
        'auto_assigned',
        'notified_at',
    ];

    protected $casts = [
        'auto_assigned' => 'boolean',
        'notified_at'   => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}