<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'outlet_id',
        'quantity_on_hand',
        'quantity_reserved',
        'reorder_point',
        'reorder_quantity',
        'last_counted_at',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'quantity_reserved' => 'integer',
        'reorder_point' => 'integer',
        'reorder_quantity' => 'integer',
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

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Scopes
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point');
    }

    public function scopeOutOfStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) <= 0');
    }

    public function scopeInStock($query)
    {
        return $query->whereRaw('(quantity_on_hand - quantity_reserved) > 0');
    }

    /**
     * Accessors & Mutators
     */
    public function getQuantityAvailableAttribute()
    {
        return max(0, $this->quantity_on_hand - $this->quantity_reserved);
    }

    /**
     * Helper methods
     */
    public function isLowStock()
    {
        return $this->quantity_available <= $this->reorder_point;
    }

    public function isOutOfStock()
    {
        return $this->quantity_available <= 0;
    }

    public function adjustQuantity($quantity, $type, $referenceType = null, $referenceId = null, $userId = null)
    {
        $oldQuantity = $this->quantity_on_hand;
        $this->quantity_on_hand += $quantity;
        $this->save();

        // Log transaction
        InventoryTransaction::create([
            'inventory_item_id' => $this->id,
            'transaction_type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'quantity_change' => $quantity,
            'quantity_before' => $oldQuantity,
            'quantity_after' => $this->quantity_on_hand,
            'created_by' => $userId ?? auth()->id(),
        ]);

        return $this;
    }

    // ── Reservation model (POS) ───────────────────────────────────────────────
    // A pending sale RESERVES stock (lowers what's sellable via quantity_available
    // without touching the physical quantity_on_hand); payment COMMITS it (goods
    // physically leave); void/cancel RELEASES the reservation. This keeps
    // quantity_on_hand honest even while a sale is open.

    /** Reserve units for a pending sale — physical count unchanged. Non-throwing. */
    public function reserveUnits(int $qty): void
    {
        if ($qty <= 0) return;
        $this->quantity_reserved += $qty;
        $this->save();
    }

    /** Commit a reservation: the goods physically leave (deduct on_hand + reserved). */
    public function commitReservation(int $qty, $referenceType = null, $referenceId = null, $userId = null): void
    {
        if ($qty <= 0) return;
        $oldQuantity = $this->quantity_on_hand;
        $this->quantity_on_hand  = max(0, $this->quantity_on_hand - $qty);
        $this->quantity_reserved = max(0, $this->quantity_reserved - $qty);
        $this->save();

        InventoryTransaction::create([
            'inventory_item_id' => $this->id,
            'transaction_type'  => 'sale',
            'reference_type'    => $referenceType,
            'reference_id'      => $referenceId,
            'quantity_change'   => $this->quantity_on_hand - $oldQuantity,
            'quantity_before'   => $oldQuantity,
            'quantity_after'    => $this->quantity_on_hand,
            'created_by'        => $userId ?? auth()->id(),
        ]);
    }

    public function reserve($quantity)
    {
        if ($quantity > $this->quantity_available) {
            throw new \Exception('Insufficient stock available to reserve');
        }

        $this->quantity_reserved += $quantity;
        $this->save();

        return $this;
    }

    public function release($quantity)
    {
        $this->quantity_reserved = max(0, $this->quantity_reserved - $quantity);
        $this->save();

        return $this;
    }
}
