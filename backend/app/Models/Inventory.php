<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_type',
        'product_id',
        'product_variant_id',
        'material_id',
        'outlet_id',
        'sku',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_damaged',
        'quantity_in_transit',
        'reorder_point',
        'reorder_quantity',
        'minimum_stock_level',
        'maximum_stock_level',
        'cost_per_unit',
        'unit_of_measure',
        'bin_location',
        'batch_number',
        'expiry_date',
        'status',
        'last_counted_at',
        'notes',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:2',
        'quantity_reserved' => 'decimal:2',
        'quantity_damaged' => 'decimal:2',
        'quantity_in_transit' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'reorder_quantity' => 'decimal:2',
        'minimum_stock_level' => 'decimal:2',
        'maximum_stock_level' => 'decimal:2',
        'cost_per_unit' => 'decimal:2',
        'expiry_date' => 'date',
        'last_counted_at' => 'datetime',
    ];

    protected $appends = ['quantity_available'];

    /**
     * Relationships
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Scopes
     */
    public function scopeProducts($query)
    {
        return $query->where('inventory_type', 'product');
    }

    public function scopeMaterials($query)
    {
        return $query->where('inventory_type', 'material');
    }

    public function scopeInStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) > 0');
    }

    public function scopeOutOfStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= 0');
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
                     ->whereRaw('(quantity_on_hand - quantity_reserved) > 0');
    }

    public function scopeExpiring($query, $days = 30)
    {
        return $query->whereNotNull('expiry_date')
                     ->where('expiry_date', '<=', now()->addDays($days))
                     ->where('expiry_date', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')
                     ->where('expiry_date', '<=', now());
    }

    /**
     * Accessors
     */
    public function getQuantityAvailableAttribute()
    {
        return max(0, $this->quantity_on_hand - $this->quantity_reserved);
    }

    public function getStockValueAttribute()
    {
        return $this->quantity_on_hand * ($this->cost_per_unit ?? 0);
    }

    public function getStockPercentageAttribute()
    {
        if (!$this->maximum_stock_level || $this->maximum_stock_level == 0) {
            return 0;
        }

        return ($this->quantity_on_hand / $this->maximum_stock_level) * 100;
    }

    /**
     * Helper methods
     */
    public function isInStock()
    {
        return $this->quantity_available > 0;
    }

    public function isOutOfStock()
    {
        return $this->quantity_available <= 0;
    }

    public function isLowStock()
    {
        return $this->reorder_point && $this->quantity_available <= $this->reorder_point && $this->quantity_available > 0;
    }

    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon($days = 30)
    {
        if (!$this->expiry_date) {
            return false;
        }

        return $this->expiry_date->lte(now()->addDays($days)) && 
               $this->expiry_date->gt(now());
    }

    public function needsReorder()
    {
        return $this->isLowStock() || $this->isOutOfStock();
    }

    public function adjustQuantity($quantity, $type = 'manual', $referenceType = null, $referenceId = null, $userId = null)
    {
        $oldQuantity = $this->quantity_on_hand;
        $this->quantity_on_hand += $quantity;
        
        // Auto-update status
        $this->updateStatus();
        
        $this->save();

        // Cast to int: the inventory_transactions table stores quantity columns as
        // integer in PostgreSQL. The Inventory model casts quantity_on_hand as
        // decimal:2 (returning "0.00" etc.), which PostgreSQL rejects for integer
        // columns. Product/variant stock is always whole units.
        InventoryTransaction::create([
            'inventory_id'     => $this->id,
            'transaction_type' => $type,
            'reference_type'   => $referenceType,
            'reference_id'     => $referenceId,
            'quantity_change'  => (int) round((float) $quantity),
            'quantity_before'  => (int) round((float) $oldQuantity),
            'quantity_after'   => (int) round((float) $this->quantity_on_hand),
            'unit_cost'        => $this->cost_per_unit,
            'created_by'       => $userId ?? auth()->id(),
        ]);

        return $this;
    }

    public function reserve($quantity)
    {
        if ($quantity > $this->quantity_available) {
            throw new \Exception('Insufficient stock available to reserve');
        }

        $this->quantity_reserved += $quantity;
        $this->updateStatus();
        $this->save();

        return $this;
    }

    public function release($quantity)
    {
        $this->quantity_reserved = max(0, $this->quantity_reserved - $quantity);
        $this->updateStatus();
        $this->save();

        return $this;
    }

    public function markAsDamaged($quantity, $notes = null)
    {
        if ($quantity > $this->quantity_on_hand) {
            throw new \Exception('Damaged quantity exceeds available stock');
        }

        $this->quantity_on_hand -= $quantity;
        $this->quantity_damaged += $quantity;
        $this->updateStatus();
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Damaged: $notes";
        }
        
        $this->save();

        return $this;
    }

    public function updateStatus()
    {
        if ($this->isExpired()) {
            $this->status = 'expired';
        } elseif ($this->isOutOfStock()) {
            $this->status = 'out_of_stock';
        } elseif ($this->isLowStock()) {
            $this->status = 'low_stock';
        } else {
            $this->status = 'available';
        }

        return $this;
    }

    public function performStockCount($countedQuantity, $userId = null)
    {
        $difference = $countedQuantity - $this->quantity_on_hand;
        
        if ($difference != 0) {
            $this->adjustQuantity(
                $difference,
                'stock_count',
                'stock_count',
                $this->id,
                $userId
            );
        }

        $this->last_counted_at = now();
        $this->save();

        return [
            'difference' => $difference,
            'old_quantity' => $this->quantity_on_hand - $difference,
            'new_quantity' => $this->quantity_on_hand,
        ];
    }

    public function getReorderSuggestion()
    {
        if (!$this->needsReorder()) {
            return null;
        }

        $suggestedQuantity = $this->reorder_quantity ?? 
                           ($this->maximum_stock_level - $this->quantity_on_hand) ??
                           ($this->reorder_point * 2);

        return [
            'current_stock' => $this->quantity_available,
            'reorder_point' => $this->reorder_point,
            'suggested_quantity' => $suggestedQuantity,
            'urgency' => $this->isOutOfStock() ? 'high' : 'medium',
        ];
    }
}