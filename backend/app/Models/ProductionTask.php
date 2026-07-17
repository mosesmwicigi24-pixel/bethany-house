<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_order_id',
        'production_stage_id',
        'sequence',
        'status',
        'concurrent_allowed',
        'unlocked_by',
        'unlocked_at',
        'assigned_to',
        'estimated_hours',
        'actual_hours',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'concurrent_allowed' => 'boolean',
        'unlocked_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];


    /** Statuses that satisfy a successor's gate. */
    public const SATISFIED_STATUSES = ['completed', 'skipped'];

    /**
     * The unfinished task standing in front of this one, or null when this task
     * is free to start.
     *
     * Stages run strictly in sequence — one tailor making one shirt cuts, then
     * stitches, then buttons — unless a production manager has explicitly marked
     * THIS task concurrent_allowed (embroidery running beside stitching on
     * separate pieces that merge later). Tasks with no sequence (legacy rows the
     * backfill could not reach) are never blocked: fail open rather than freeze
     * a floor mid-order.
     */
    public function blockingTask(): ?self
    {
        if ($this->concurrent_allowed || $this->sequence === null) {
            return null;
        }

        return self::where('production_order_id', $this->production_order_id)
            ->whereNotNull('sequence')
            ->where('sequence', '<', $this->sequence)
            ->whereNotIn('status', self::SATISFIED_STATUSES)
            ->orderBy('sequence')
            ->with('stage:id,name')
            ->first();
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function stage()
    {
        return $this->belongsTo(ProductionStage::class, 'production_stage_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function start()
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return $this;
    }

    public function complete($actualHours = null)
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_hours' => $actualHours,
        ]);

        return $this;
    }
}
