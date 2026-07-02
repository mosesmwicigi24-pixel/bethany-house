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
        'status',
        'assigned_to',
        'estimated_hours',
        'actual_hours',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

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
