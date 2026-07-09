<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Order;

/**
 * Drives the POS reservation model at the order level, tracked by three
 * timestamps on the order so every transition is idempotent and transition-safe:
 *
 *   reserve (order created)  → quantity_reserved += qty ; stock_reserved_at
 *   commit  (order paid)     → on_hand -= qty, reserved -= qty ; stock_committed_at
 *   unwind  (void / cancel)  → committed ? restore on_hand : release reservation ; stock_unwound_at
 *
 * quantity_on_hand (the physical count) therefore only moves when goods actually
 * leave (commit) or come back (unwind of a committed order) — never merely
 * because a sale is open. Made-to-order lines and non-inventory lines are
 * skipped.
 */
class PosInventoryService
{
    /** POS persists made-to-order lines with a `__MTO__` note prefix. */
    private static function isMto(object $item): bool
    {
        return str_starts_with((string) ($item->notes ?? ''), '__MTO__');
    }

    /** The finished-goods row a line draws from, matched by variant + outlet. */
    private static function inventoryFor(object $item, ?int $outletId): ?InventoryItem
    {
        return InventoryItem::where('product_variant_id', $item->product_variant_id)
            ->where('outlet_id', $outletId)
            ->first()
            ?? InventoryItem::whereNull('outlet_id')
                ->where('product_variant_id', $item->product_variant_id)
                ->first();
    }

    /** Reserve stock for a just-created/updated pending order. */
    public static function reserveForOrder(Order $order): void
    {
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            if (self::isMto($item)) continue;
            self::inventoryFor($item, $order->outlet_id)?->reserveUnits((int) $item->quantity);
        }
        $order->forceFill(['stock_reserved_at' => now()])->save();
    }

    /** Release the reservation held by a specific set of (about-to-be-replaced) items. */
    public static function releaseReservationForItems(Order $order, iterable $items): void
    {
        foreach ($items as $item) {
            if (self::isMto($item)) continue;
            self::inventoryFor($item, $order->outlet_id)?->release((int) $item->quantity);
        }
    }

    /** Commit the reservation on payment — goods leave the shelf. Idempotent. */
    public static function commitForOrder(Order $order, ?int $userId = null): void
    {
        // Only commit a reservation once, and never re-deduct an order created
        // under the old model (already marked committed by the migration).
        if ($order->stock_committed_at || !$order->stock_reserved_at) {
            return;
        }

        $order->loadMissing('items');
        foreach ($order->items as $item) {
            if (self::isMto($item)) continue;
            self::inventoryFor($item, $order->outlet_id)
                ?->commitReservation((int) $item->quantity, Order::class, $order->id, $userId);
        }
        $order->forceFill(['stock_committed_at' => now()])->save();
    }

    /**
     * Return an order's stock on void/cancel. If it was committed the goods had
     * physically left, so put them back on the shelf; if only reserved, release
     * the reservation (physical count never moved). Idempotent.
     */
    public static function unwindForOrder(Order $order, ?int $userId = null): void
    {
        if ($order->stock_unwound_at) {
            return;
        }

        $order->loadMissing('items');
        $committed = (bool) $order->stock_committed_at;

        foreach ($order->items as $item) {
            if (self::isMto($item)) continue;
            $inv = self::inventoryFor($item, $order->outlet_id);
            if (!$inv) continue;

            if ($committed) {
                $inv->adjustQuantity((int) $item->quantity, 'void_return', Order::class, $order->id, $userId);
            } else {
                $inv->release((int) $item->quantity);
            }
        }
        $order->forceFill(['stock_unwound_at' => now()])->save();
    }
}
