<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionTaskBatchProgress extends Model
{
    protected $table = 'production_task_batch_progress';

    protected $fillable = [
        'production_task_id',
        'production_order_batch_id',
        'quantity_done',
    ];

    protected $casts = [
        'quantity_done' => 'integer',
    ];

    public function task()
    {
        return $this->belongsTo(ProductionTask::class, 'production_task_id');
    }

    public function batch()
    {
        return $this->belongsTo(ProductionOrderBatch::class, 'production_order_batch_id');
    }
}
