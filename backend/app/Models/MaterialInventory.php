<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialInventory extends Model
{
    use HasFactory;

    protected $table = 'material_inventory'; // Laravel would guess 'material_inventories'

    protected $fillable = [
        'material_id',
        'outlet_id',
        'quantity_on_hand',
        'last_counted_at',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'last_counted_at'  => 'datetime',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function transactions()
    {
        return $this->hasMany(MaterialTransaction::class);
    }

    /**
     * Adjust quantity and log a transaction in one call.
     * Used by production module for material allocation.
     */
    public function adjustQuantity(
        float  $quantity,
        string $type,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        ?int    $userId        = null
    ): static {
        $before = (float) $this->quantity_on_hand;
        $this->quantity_on_hand = $before + $quantity;
        $this->save();

        MaterialTransaction::create([
            'material_inventory_id' => $this->id,
            'transaction_type'      => $type,
            'reference_type'        => $referenceType,
            'reference_id'          => $referenceId,
            'quantity_change'       => $quantity,
            'quantity_before'       => $before,
            'quantity_after'        => $this->quantity_on_hand,
            'created_by'            => $userId ?? auth()->id(),
        ]);

        return $this;
    }
}