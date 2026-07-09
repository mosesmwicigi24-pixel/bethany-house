<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single physical unit of a produced product, tracked by a unique serial from
 * production through sale and dispatch. See App\Services\ProductSerialService.
 */
class ProductSerial extends Model
{
    // Lifecycle statuses.
    public const IN_PRODUCTION = 'in_production';
    public const IN_STOCK      = 'in_stock';
    public const SOLD          = 'sold';
    public const DISPATCHED    = 'dispatched';
    public const RETURNED      = 'returned';
    public const CANCELLED     = 'cancelled';
    /** Expected on the shelf but not found during a physical reconciliation. */
    public const MISSING       = 'missing';

    protected $fillable = [
        'serial_number',
        'product_id',
        'product_variant_id',
        'production_order_id',
        'source_reference',
        'inventory_item_id',
        'outlet_id',
        'status',
        'stocked_at',
        'order_id',
        'sold_at',
        'dispatched_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'stocked_at'    => 'datetime',
        'sold_at'       => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** The sale that sold this unit (Phase 2). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
