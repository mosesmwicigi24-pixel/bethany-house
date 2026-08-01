<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Why pieces were reworked, scrapped or stopped.
 *
 * Mandatory on REWORK, SCRAP and HOLD — that single requirement is what turns
 * the movement ledger into a defect Pareto without anyone running a separate
 * data-collection exercise.
 */
class MovementReason extends Model
{
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;

    protected $fillable = [
        'code', 'label', 'applies_to', 'is_defect', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_defect'  => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFor($query, string $movementType)
    {
        return $query->where('applies_to', $movementType);
    }
}
