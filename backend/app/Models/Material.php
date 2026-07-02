<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    // Actual DB columns:
    // id, code, name, description, unit_of_measure, category,
    // unit_cost, reorder_point, is_active, created_at, updated_at

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',           // NOT material_type
        'unit_of_measure',
        'unit_cost',          // NOT cost_per_unit
        'reorder_point',
        'is_active',
    ];

    protected $casts = [
        'unit_cost'     => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function inventory()
    {
        return $this->hasMany(MaterialInventory::class);
    }

    public function transactions()
    {
        return $this->hasMany(MaterialTransaction::class);
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(MaterialAllocation::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->where('is_active', true)
            ->where('reorder_point', '>', 0)
            ->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) <= materials.reorder_point');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getTotalStock(): float
    {
        return (float) $this->inventory()->sum('quantity_on_hand');
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point > 0 && $this->getTotalStock() <= $this->reorder_point;
    }

    // ── Aliases for backward compatibility with old code ──────────────────────

    public function getCostPerUnitAttribute(): float
    {
        return (float) $this->unit_cost;
    }

    public function getMaterialTypeAttribute(): ?string
    {
        return $this->category;
    }
}