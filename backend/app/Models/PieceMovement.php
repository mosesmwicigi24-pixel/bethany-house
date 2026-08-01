<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the append-only movement ledger — the source of truth for where
 * every piece is.
 *
 * Rows are never updated and never deleted. An undo posts a compensating
 * REVERSAL row pointing at the original via `reverses_id`, so the history shows
 * both the mistake and the correction.
 *
 * Sidedness matters and is enforced by CHECK constraints:
 *   INTAKE                — credit only (no origin): pieces enter the line
 *   REVERSAL of an INTAKE — debit only (no destination): pieces leave it again
 *   everything else       — a two-sided transfer, one stage loses what another gains
 */
class PieceMovement extends Model
{
    public const TYPE_INTAKE   = 'INTAKE';
    public const TYPE_FORWARD  = 'FORWARD';
    public const TYPE_REWORK   = 'REWORK';
    public const TYPE_SCRAP    = 'SCRAP';
    public const TYPE_HOLD     = 'HOLD';
    public const TYPE_RELEASE  = 'RELEASE';
    public const TYPE_REVERSAL = 'REVERSAL';

    public const BUCKET_READY = 'READY';
    public const BUCKET_HELD  = 'HELD';

    /** Movements that must always say why. */
    public const REASON_REQUIRED = [self::TYPE_REWORK, self::TYPE_SCRAP, self::TYPE_HOLD];

    public $timestamps = false;

    protected $fillable = [
        'client_ref', 'production_order_id', 'production_order_batch_id',
        'production_workflow_id', 'movement_type', 'from_stage_id', 'to_stage_id',
        'from_bucket', 'to_bucket', 'quantity', 'reason_code', 'note',
        'moved_by', 'moved_at', 'device_id', 'reverses_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'moved_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(ProductionOrderBatch::class, 'production_order_batch_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function fromStage()
    {
        return $this->belongsTo(ProductionWorkflowStage::class, 'from_stage_id');
    }

    public function toStage()
    {
        return $this->belongsTo(ProductionWorkflowStage::class, 'to_stage_id');
    }

    public function reason()
    {
        return $this->belongsTo(MovementReason::class, 'reason_code', 'code');
    }

    public function mover()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
