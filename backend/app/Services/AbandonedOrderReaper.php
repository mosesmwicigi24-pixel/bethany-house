<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps inventory honest around abandoned POS orders.
 *
 * The two-step POS deducts finished-goods stock the moment a pending order is
 * created (goods reserved off the shelf). If that sale is never completed the
 * stock stays deducted. Voids already restore it; abandoned/cancelled orders did
 * not — so the shelf count silently drifted down.
 *
 * This restores the deducted stock for such orders (idempotently, guarded by the
 * inventory-transaction ledger so it can never double-restore), reaps
 * genuinely-abandoned ones on a schedule, and backfills the stock that earlier
 * cleanups cancelled without restoring.
 */
class AbandonedOrderReaper
{
    /** POS persists made-to-order lines with a `__MTO__` note prefix (no stock). */
    private static function isMto(object $item): bool
    {
        return str_starts_with((string) ($item->notes ?? ''), '__MTO__');
    }

    /**
     * Restore the stock an order deducted at creation. Idempotent: a no-op if the
     * order was already restored (by a void or a previous reap).
     *
     * @return int units restored
     */
    public static function restoreInventoryForOrder(int $orderId, ?int $userId = null): int
    {
        if (!Schema::hasTable('orders') || !Schema::hasTable('inventory_items')) {
            return 0;
        }

        $alreadyRestored = InventoryTransaction::where('reference_type', Order::class)
            ->where('reference_id', $orderId)
            ->whereIn('transaction_type', ['void_return', 'abandon_restore'])
            ->exists();
        if ($alreadyRestored) {
            return 0;
        }

        $order = Order::with('items')->find($orderId);
        if (!$order) {
            return 0;
        }

        $restored = 0;
        foreach ($order->items as $item) {
            if (self::isMto($item)) {
                continue;
            }
            // Restore to the same finished-goods row the sale deducted from.
            $inventory = InventoryItem::where('product_variant_id', $item->product_variant_id)
                ->where('outlet_id', $order->outlet_id)
                ->first();
            if ($inventory) {
                $inventory->adjustQuantity(
                    (int) $item->quantity,
                    'abandon_restore',
                    Order::class,
                    $order->id,
                    $userId,
                );
                $restored += (int) $item->quantity;
            }
        }

        return $restored;
    }

    /**
     * Cancel abandoned unpaid pending POS orders older than $hours, restoring
     * their stock and releasing their serials. Never touches an order with any
     * money attached. Idempotent.
     *
     * @return array{cancelled:int, restored:int}
     */
    public static function reap(int $hours = 24, ?int $userId = null): array
    {
        $result = ['cancelled' => 0, 'restored' => 0];

        if (!Schema::hasTable('orders')) {
            return $result;
        }

        $cutoff = now()->subHours($hours);

        $orderIds = DB::table('orders')
            ->where('order_type', 'pos')
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        foreach ($orderIds as $orderId) {
            $hasMoney = Schema::hasTable('payments') && DB::table('payments')
                ->where('order_id', $orderId)
                ->whereIn('status', ['paid', 'pending', 'partial'])
                ->exists();
            if ($hasMoney) {
                continue;
            }

            DB::transaction(function () use ($orderId, $userId, &$result) {
                $result['restored'] += self::restoreInventoryForOrder($orderId, $userId);

                $order = Order::find($orderId);
                if (!$order) {
                    return;
                }
                ProductSerialService::releaseForOrder($order);

                $note = trim(($order->notes ?? '') . "\n[system] Auto-cancelled: abandoned unpaid POS order (stock restored).");
                $order->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => $order->cancelled_at ?? now(),
                    'notes'        => $note,
                ]);
                $result['cancelled']++;
            });
        }

        return $result;
    }

    /**
     * Backfill: restore stock for POS orders that were cancelled WITHOUT restoring
     * (e.g. the earlier one-time cleanup). Idempotent via the ledger guard.
     *
     * @return array{restored_orders:int, restored_units:int}
     */
    public static function backfillCancelledUnrestored(): array
    {
        $result = ['restored_orders' => 0, 'restored_units' => 0];

        if (!Schema::hasTable('orders')) {
            return $result;
        }

        $orderIds = DB::table('orders')
            ->where('order_type', 'pos')
            ->where('status', 'cancelled')
            ->where('notes', 'ILIKE', '%Auto-cancelled: abandoned unpaid POS order%')
            ->pluck('id');

        foreach ($orderIds as $orderId) {
            $units = self::restoreInventoryForOrder($orderId);
            if ($units > 0) {
                $result['restored_orders']++;
                $result['restored_units'] += $units;
            }
        }

        return $result;
    }
}
