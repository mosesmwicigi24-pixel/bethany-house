<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A liturgical season that themes the storefront and (optionally) runs a
 * Blessed Friday campaign. Mirrors the Promotion model's active-window
 * conventions so the two read the same way.
 */
class Season extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'tagline', 'scripture', 'theme',
        'starts_at', 'ends_at', 'is_active', 'priority',
        'promotion_id', 'banner_id', 'sort_order',
    ];

    protected $casts = [
        'theme'      => 'array',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'is_active'  => 'boolean',
        'priority'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }

    /** Enabled and within its date window (a windowless season is always eligible). */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isRunning(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;
        return true;
    }
}
