<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Banner extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'subtitle', 'image_url', 'mobile_image_url',
        'link_url', 'link_text', 'position', 'placement',
        'is_active', 'open_in_new_tab', 'sort_order',
        'starts_at', 'ends_at', 'styles', 'created_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'open_in_new_tab' => 'boolean',
        'sort_order'      => 'integer',
        'starts_at'       => 'datetime',
        'ends_at'         => 'datetime',
        'styles'          => 'array',
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

    public function isLive(): bool
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
        return 'live';
    }
}