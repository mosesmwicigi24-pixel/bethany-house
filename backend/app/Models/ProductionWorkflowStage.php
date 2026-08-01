<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One station on a versioned line.
 *
 * `kind` carries the grammar: QUEUE is the entry buffer, WORK adds value,
 * INSPECT may send pieces backwards, DONE and SCRAP are terminal. `seq` is the
 * order pieces travel in; SCRAP sits at 9999, off the line.
 *
 * `production_stage_id` points back at the legacy global stage catalogue, which
 * is what the task system still keys on. Terminals have none — they are
 * movement-system structure, not shop-floor stations.
 */
class ProductionWorkflowStage extends Model
{
    public const KIND_QUEUE   = 'QUEUE';
    public const KIND_WORK    = 'WORK';
    public const KIND_INSPECT = 'INSPECT';
    public const KIND_DONE    = 'DONE';
    public const KIND_SCRAP   = 'SCRAP';

    /** Stages pieces actually travel through, in order. Scrap is off-line. */
    public const LINE_KINDS = [self::KIND_QUEUE, self::KIND_WORK, self::KIND_INSPECT, self::KIND_DONE];

    protected $fillable = [
        'production_workflow_id', 'production_stage_id', 'code', 'name', 'seq',
        'kind', 'wip_limit', 'target_minutes', 'allows_rework', 'default_role', 'color',
        'icon', 'checklist', 'daily_target',
    ];

    protected $casts = [
        'seq'            => 'integer',
        'wip_limit'      => 'integer',
        'target_minutes' => 'integer',
        'allows_rework'  => 'boolean',
        'checklist'      => 'array',
        'daily_target'   => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(ProductionWorkflow::class, 'production_workflow_id');
    }

    /** The legacy catalogue row this station speaks for, if any. */
    public function legacyStage()
    {
        return $this->belongsTo(ProductionStage::class, 'production_stage_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->kind, [self::KIND_DONE, self::KIND_SCRAP], true);
    }

    public function isOnLine(): bool
    {
        return $this->kind !== self::KIND_SCRAP;
    }
}
