<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How many pieces of one batch are standing at one stage right now.
 *
 * This is a PROJECTION, not the truth. `piece_movements` is the truth, and
 * `fn_rebuild_batch_state()` can reconstruct every row here from it. Nothing
 * outside MovementService may write to this table — the conservation invariant
 * only holds because every change arrives as a matched debit and credit inside
 * one transaction.
 *
 * `qty_ready` is workable. `qty_held` is stopped, for a reason recorded on the
 * movement that stopped it. Only ready pieces can move forward. The stage chip
 * shows the total; the alert shows the split.
 */
class BatchStageState extends Model
{
    protected $table = 'batch_stage_states';

    /** Composite key (batch, stage); rows are written by the engine, not by Eloquent. */
    public $incrementing = false;
    public $timestamps   = false;
    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = [
        'qty_ready'   => 'integer',
        'qty_held'    => 'integer',
        'version'     => 'integer',
        'entered_at'  => 'datetime',
        'last_in_at'  => 'datetime',
        'last_out_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(ProductionOrderBatch::class, 'production_order_batch_id');
    }

    public function stage()
    {
        return $this->belongsTo(ProductionWorkflowStage::class, 'production_workflow_stage_id');
    }

    public function total(): int
    {
        return (int) $this->qty_ready + (int) $this->qty_held;
    }
}
