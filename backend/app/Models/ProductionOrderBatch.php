<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderBatch extends Model
{
    protected $fillable = [
        'production_order_id',
        'label',
        'attributes',
        'images',
        'quantity',
        'sort_order',
    ];

    protected $casts = [
        'attributes' => 'array',
        'images'     => 'array',
        'quantity'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function progress()
    {
        return $this->hasMany(ProductionTaskBatchProgress::class, 'production_order_batch_id');
    }
}
