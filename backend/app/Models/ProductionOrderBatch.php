<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProductionOrderBatch extends Model
{
    protected $fillable = [
        'production_order_id',
        'production_workflow_id',
        'label',
        'attributes',
        'images',
        'quantity',
        'loaded_qty',
        'sort_order',
        'due_at',
    ];

    protected $casts = [
        'attributes' => 'array',
        'images'     => 'array',
        'quantity'   => 'integer',
        'loaded_qty' => 'integer',
        'sort_order' => 'integer',
        'due_at'     => 'datetime',
    ];

    public function workflow()
    {
        return $this->belongsTo(ProductionWorkflow::class, 'production_workflow_id');
    }

    public function movements()
    {
        return $this->hasMany(PieceMovement::class, 'production_order_batch_id');
    }

    public function stageStates()
    {
        return $this->hasMany(BatchStageState::class, 'production_order_batch_id');
    }

    /**
     * What the customer must still receive from this batch if nothing else is
     * written off. `quantity` is what was ordered; `loaded_qty` is what has
     * physically been introduced, which grows when scrapped pieces are re-cut.
     */
    public function shortfall(): int
    {
        $scrapped = (int) DB::table('batch_stage_states as s')
            ->join('production_workflow_stages as st', 'st.id', '=', 's.production_workflow_stage_id')
            ->where('s.production_order_batch_id', $this->id)
            ->where('st.kind', ProductionWorkflowStage::KIND_SCRAP)
            ->sum(DB::raw('s.qty_ready + s.qty_held'));

        return max(0, (int) $this->quantity - ((int) $this->loaded_qty - $scrapped));
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function progress()
    {
        return $this->hasMany(ProductionTaskBatchProgress::class, 'production_order_batch_id');
    }
}
