<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductionOrder;
use App\Models\ProductSerial;

/**
 * Manages the per-unit serial lifecycle for produced items.
 *
 * Flow:
 *   - Production order APPROVED (confirm)  → generateForProductionOrder(): one
 *     serial per unit, status in_production.
 *   - Production order COMPLETED           → stockFromProductionOrder(): those
 *     serials become in_stock (linked to the finished-goods inventory item),
 *     reconciled to the final produced quantity.
 *   - Production order CANCELLED           → cancelForProductionOrder().
 */
class ProductSerialService
{
    /** `PRD-20260709-0001-001` — traceable to its production order, scan-ready. */
    private static function format(string $orderNumber, int $seq): string
    {
        return $orderNumber . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Assign a unique serial to every unit of a just-approved production order.
     * Idempotent — does nothing if serials already exist for the order.
     *
     * @return int number of serials created
     */
    public static function generateForProductionOrder(ProductionOrder $order): int
    {
        if (ProductSerial::where('production_order_id', $order->id)->exists()) {
            return 0;
        }

        $qty = (int) $order->quantity;
        if ($qty < 1) {
            return 0;
        }

        for ($i = 1; $i <= $qty; $i++) {
            ProductSerial::create([
                'serial_number'       => self::format($order->order_number, $i),
                'product_id'          => $order->product_id,
                'product_variant_id'  => $order->product_variant_id,
                'production_order_id'  => $order->id,
                'outlet_id'           => $order->outlet_id,
                'status'              => ProductSerial::IN_PRODUCTION,
                'created_by'          => $order->created_by,
            ]);
        }

        return $qty;
    }

    /**
     * On completion, move the order's in-production serials into stock, reconciled
     * to the actual produced quantity: flip up to $finalQty into stock (linked to
     * the finished-goods inventory item), cancel any surplus, and create extras if
     * more units were produced than were ordered.
     */
    public static function stockFromProductionOrder(
        ProductionOrder $order,
        ?int $inventoryItemId,
        ?int $outletId,
        int $finalQty,
    ): void {
        $serials = ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_PRODUCTION)
            ->orderBy('id')
            ->get();

        $i = 0;
        foreach ($serials as $serial) {
            if ($i < $finalQty) {
                $serial->update([
                    'status'            => ProductSerial::IN_STOCK,
                    'inventory_item_id' => $inventoryItemId,
                    'outlet_id'         => $outletId ?? $serial->outlet_id,
                ]);
            } else {
                $serial->update([
                    'status' => ProductSerial::CANCELLED,
                    'notes'  => trim(($serial->notes ?? '') . "\nCancelled: fewer units produced than ordered."),
                ]);
            }
            $i++;
        }

        // Produced more than were ordered (or serials were never generated at
        // approval) — mint the remainder straight into stock.
        for ($seq = $serials->count() + 1; $seq <= $finalQty; $seq++) {
            ProductSerial::create([
                'serial_number'      => self::format($order->order_number, $seq),
                'product_id'         => $order->product_id,
                'product_variant_id' => $order->product_variant_id,
                'production_order_id' => $order->id,
                'inventory_item_id'  => $inventoryItemId,
                'outlet_id'          => $outletId ?? $order->outlet_id,
                'status'             => ProductSerial::IN_STOCK,
                'created_by'         => $order->created_by,
            ]);
        }
    }

    /** Cancel the still-in-production serials of a cancelled production order. */
    public static function cancelForProductionOrder(ProductionOrder $order): void
    {
        ProductSerial::where('production_order_id', $order->id)
            ->where('status', ProductSerial::IN_PRODUCTION)
            ->update(['status' => ProductSerial::CANCELLED, 'updated_at' => now()]);
    }

    // ── Sale linkage (Phase 2) ────────────────────────────────────────────────

    /**
     * Reconcile an order's SOLD serials to match its current line items — called
     * wherever POS pulls stock off the shelf (create / update pending order).
     * For each serialized product it claims the right number of in-stock serials
     * (FIFO, preferring the order's outlet) and releases any surplus back to
     * stock, so the sold set always mirrors what's actually on the receipt.
     * Made-to-order lines are skipped (no shelf stock / serial yet). Products
     * with no serials at all are simply ignored (nothing to track).
     */
    public static function syncSoldForOrder(Order $order): void
    {
        $order->loadMissing('items');

        // Desired sold quantity per serialized product (skip MTO lines).
        $desired = [];
        foreach ($order->items as $item) {
            if (!$item->product_id || self::isMto($item)) {
                continue;
            }
            $desired[$item->product_id] = ($desired[$item->product_id] ?? 0) + (int) $item->quantity;
        }

        // Currently sold-to-this-order, grouped by product.
        $current = ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)
            ->get()
            ->groupBy('product_id');

        $productIds = collect(array_keys($desired))->merge($current->keys())->unique();

        foreach ($productIds as $productId) {
            $want = $desired[$productId] ?? 0;
            $have = $current->get($productId)?->count() ?? 0;

            if ($have < $want) {
                $take = ProductSerial::where('product_id', $productId)
                    ->where('status', ProductSerial::IN_STOCK)
                    ->when($order->outlet_id, fn ($q) => $q->where(
                        fn ($qq) => $qq->where('outlet_id', $order->outlet_id)->orWhereNull('outlet_id'),
                    ))
                    ->orderBy('id')
                    ->limit($want - $have)
                    ->get();
                foreach ($take as $serial) {
                    $serial->update([
                        'status'   => ProductSerial::SOLD,
                        'order_id' => $order->id,
                        'sold_at'  => now(),
                    ]);
                }
            } elseif ($have > $want) {
                foreach ($current->get($productId)->take($have - $want) as $serial) {
                    $serial->update([
                        'status'   => ProductSerial::IN_STOCK,
                        'order_id' => null,
                        'sold_at'  => null,
                    ]);
                }
            }
        }
    }

    /** Mark an order's sold serials as dispatched (hand-over authorized). */
    public static function dispatchForOrder(Order $order): int
    {
        return ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)
            ->update([
                'status'        => ProductSerial::DISPATCHED,
                'dispatched_at' => now(),
                'updated_at'    => now(),
            ]);
    }

    /** Return an order's sold serials to stock — on void / release. */
    public static function releaseForOrder(Order $order): void
    {
        ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)
            ->update([
                'status'     => ProductSerial::IN_STOCK,
                'order_id'   => null,
                'sold_at'    => null,
                'updated_at' => now(),
            ]);
    }

    /** POS persists made-to-order lines with a `__MTO__` note prefix. */
    private static function isMto(object $item): bool
    {
        return str_starts_with((string) ($item->notes ?? ''), '__MTO__');
    }
}
