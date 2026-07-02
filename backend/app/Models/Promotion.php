<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'type', 'discount_value', 'discount_type',
        'conditions', 'is_active', 'starts_at', 'ends_at',
        'priority', 'is_exclusive', 'max_uses', 'times_used',
        'banner_image', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'is_active'      => 'boolean',
        'is_exclusive'   => 'boolean',
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
        'conditions'     => 'array',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isRunning(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;
        return true;
    }

    public function getStatusAttribute(): string
    {
        if (!$this->is_active) return 'inactive';
        if ($this->ends_at && $this->ends_at->isPast()) return 'expired';
        if ($this->starts_at && $this->starts_at->isFuture()) return 'scheduled';
        if ($this->max_uses && $this->times_used >= $this->max_uses) return 'exhausted';
        return 'active';
    }
}