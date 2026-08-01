<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A versioned production line. Batches pin the exact row they started on, so
 * re-sequencing the template tomorrow cannot rewrite an order that is halfway
 * down the line — editing a workflow creates version n+1 instead.
 */
class ProductionWorkflow extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'version', 'is_active', 'is_default', 'created_by',
    ];

    protected $casts = [
        'version'    => 'integer',
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    public function stages()
    {
        return $this->hasMany(ProductionWorkflowStage::class)->orderBy('seq');
    }

    public function batches()
    {
        return $this->hasMany(ProductionOrderBatch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The line a batch is pinned to when nobody names one. */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::where('is_active', true)->orderBy('id')->first();
    }
}
