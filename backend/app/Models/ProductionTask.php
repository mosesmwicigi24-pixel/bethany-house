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
        'quantity_done',
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
        'quantity_done' => 'integer',
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
     * Pieces that have effectively passed this stage. A satisfied stage
     * (completed/skipped) has passed everything by definition; otherwise the
     * recorded counter speaks.
     */
    public function effectivePassed(int $orderQuantity): int
    {
        if (in_array($this->status, self::SATISFIED_STATUSES)) {
            return $orderQuantity;
        }

        return min((int) $this->quantity_done, $orderQuantity);
    }

    /**
     * The stage standing in front of this one, or null when work is available.
     *
     * With piece counting this is FLOW gating, not whole-batch gating: Button
     * may work whenever Stitching has passed more pieces than Button has — the
     * surplus is the physical pile waiting on the bench. For quantity-1 orders
     * this degrades to exactly the old rule (the predecessor must be finished),
     * so single-garment behaviour is unchanged.
     *
     * The binding constraint is the MINIMUM effective count over all earlier
     * stages (counts are monotone along a healthy pipeline, but a gap — e.g. a
     * stage force-completed out of order — must still block downstream work).
     * concurrent_allowed lifts this gate; the manager said so, visibly. Tasks
     * with no sequence fail open — a gate bug must never freeze the floor.
     */
    public function blockingTask(): ?self
    {
        if ($this->concurrent_allowed || $this->sequence === null) {
            return null;
        }

        $orderQty = (int) ($this->productionOrder?->quantity ?? 1);

        $earlier = self::where('production_order_id', $this->production_order_id)
            ->whereNotNull('sequence')
            ->where('sequence', '<', $this->sequence)
            ->orderBy('sequence')
            ->with('stage:id,name')
            ->get();

        $blocker = null;
        $minPassed = $orderQty;
        foreach ($earlier as $task) {
            $passed = $task->effectivePassed($orderQty);
            // <= on purpose: on a tie the LATEST stage takes the blame — that is
            // the bench the pieces would actually come from.
            if ($passed <= $minPassed) {
                $minPassed = $passed;
                $blocker   = $task;
            }
        }

        // Work is available while the pipeline has surplus over my own count.
        if ($minPassed > (int) $this->quantity_done) {
            return null;
        }

        return $blocker ?? $earlier->last();
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
