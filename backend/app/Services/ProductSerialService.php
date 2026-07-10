<?php

namespace App\Services;

use App\Models\InventoryItem;
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
                    'stocked_at'        => now(),
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
                'stocked_at'         => now(),
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

    // ── Received (non-manufactured) stock ─────────────────────────────────────

    /**
     * `RCV-<REF>-<productId>-<seq>` — a receipt-minted unit, distinct from
     * `PRD-…`. The product id keeps it globally unique even when several products
     * are received under one reference (e.g. one purchase order, many lines).
     */
    private static function receiptSerial(string $reference, int $productId, int $seq): string
    {
        // Keep the reference readable but scan-safe in the code.
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $reference));
        return 'RCV-' . $ref . '-' . $productId . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Mint $qty unique serials straight into stock for goods received WITHOUT a
     * production run (bought in for resale, supplier receipt, restock, upward
     * stock adjustment). These behave exactly like produced-then-stocked units
     * from here on — sellable, dispatchable, and reconcilable.
     *
     * Idempotent per $sourceReference: receiving the same reference again only
     * tops up to $qty (so re-runs and partial retries never duplicate serials).
     *
     * @return int number of serials newly created
     */
    public static function receiveIntoStock(
        int $productId,
        ?int $variantId,
        ?int $outletId,
        ?int $inventoryItemId,
        int $qty,
        string $sourceReference,
        ?int $userId = null,
    ): int {
        if ($qty < 1 || $sourceReference === '') {
            return 0;
        }

        // Already minted for this receipt? Only create the shortfall.
        $existing = ProductSerial::where('source_reference', $sourceReference)
            ->where('product_id', $productId)
            ->count();
        $toCreate = $qty - $existing;
        if ($toCreate < 1) {
            return 0;
        }

        for ($i = 1; $i <= $toCreate; $i++) {
            ProductSerial::create([
                'serial_number'      => self::receiptSerial($sourceReference, $productId, $existing + $i),
                'product_id'         => $productId,
                'product_variant_id' => $variantId,
                'production_order_id' => null,
                'source_reference'   => $sourceReference,
                'inventory_item_id'  => $inventoryItemId,
                'outlet_id'          => $outletId,
                'status'             => ProductSerial::IN_STOCK,
                'stocked_at'         => now(),
                'created_by'         => $userId,
            ]);
        }

        return $toCreate;
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
                        'status'     => ProductSerial::IN_STOCK,
                        'order_id'   => null,
                        'sold_at'    => null,
                        'stocked_at' => now(),
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

    /**
     * Return an order's serials to stock — on void / release. Covers both sold
     * and DISPATCHED units: an order can be voided after dispatch (voidSale only
     * blocks already-voided), and unwindForOrder restores its quantity_on_hand,
     * so the serials must come back to stock too or they'd be stranded
     * `dispatched` while the physical count says they're on the shelf.
     */
    public static function releaseForOrder(Order $order): void
    {
        ProductSerial::where('order_id', $order->id)
            ->whereIn('status', [ProductSerial::SOLD, ProductSerial::DISPATCHED])
            ->update([
                'status'        => ProductSerial::IN_STOCK,
                'order_id'      => null,
                'sold_at'       => null,
                'dispatched_at' => null,
                'stocked_at'    => now(),
                'updated_at'    => now(),
            ]);
    }

    /**
     * Return up to $qty of a product's units (sold or dispatched on this order)
     * to stock — for a PARTIAL return, where only some line units come back and
     * quantity_on_hand is restored by that amount. Mirrors that restock so the
     * serial count tracks the physical count.
     *
     * @return int units returned to stock
     */
    public static function returnUnitsForOrder(Order $order, int $productId, int $qty): int
    {
        if ($qty < 1) {
            return 0;
        }

        $serials = ProductSerial::where('order_id', $order->id)
            ->where('product_id', $productId)
            ->whereIn('status', [ProductSerial::SOLD, ProductSerial::DISPATCHED])
            ->orderBy('id')
            ->limit($qty)
            ->get();

        foreach ($serials as $serial) {
            $serial->update([
                'status'        => ProductSerial::IN_STOCK,
                'order_id'      => null,
                'sold_at'       => null,
                'dispatched_at' => null,
                'stocked_at'    => now(),
            ]);
        }

        return $serials->count();
    }

    /**
     * Reconcile a product's system stock against a physical count.
     *
     * Given the serial numbers actually found on the shelf, compares them to the
     * serials the system believes are in stock and returns the discrepancy:
     *   - missing:    system says in-stock but wasn't found (possible loss/theft)
     *   - unexpected: found on the shelf but not in-stock in the system
     *   - matched:    present and accounted for
     * When $flagMissing is true, the missing units are marked `missing` so the
     * loss is recorded and stops counting as sellable stock.
     *
     * @param  string[]  $scannedSerials
     * @return array{matched:array, missing:array, unexpected:array}
     */
    public static function reconcile(int $productId, ?int $outletId, array $scannedSerials, bool $flagMissing = false): array
    {
        $scanned = collect($scannedSerials)->map(fn ($s) => trim((string) $s))->filter()->unique();

        $systemInStock = ProductSerial::where('product_id', $productId)
            ->where('status', ProductSerial::IN_STOCK)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->get();

        $systemNumbers = $systemInStock->pluck('serial_number');

        $matchedNumbers    = $scanned->intersect($systemNumbers)->values();
        $missing           = $systemInStock->whereNotIn('serial_number', $scanned->all())->values();
        $unexpectedNumbers = $scanned->diff($systemNumbers)->values();

        if ($flagMissing && $missing->isNotEmpty()) {
            ProductSerial::whereIn('id', $missing->pluck('id'))->update([
                'status'     => ProductSerial::MISSING,
                'notes'      => 'Flagged missing during reconciliation on ' . now()->toDateString(),
                'updated_at' => now(),
            ]);

            // A missing unit is a real stock loss: reduce sellable quantity_on_hand
            // to match (ledgered as an `adjustment`), so the ghost unit stops being
            // sellable — the whole point of flagging it. Group by the exact row each
            // unit was stocked in; fall back to the product+outlet row for legacy
            // serials with no pinned inventory_item_id.
            foreach ($missing->groupBy('inventory_item_id') as $invId => $group) {
                $inv = $invId
                    ? InventoryItem::find($invId)
                    : InventoryItem::where('product_id', $productId)
                        ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                        ->first();
                $inv?->adjustQuantity(-1 * $group->count(), 'adjustment', null, null, null);
            }
        }

        return [
            'matched'    => $matchedNumbers->all(),
            'missing'    => $missing->map(fn ($s) => [
                'id'            => $s->id,
                'serial_number' => $s->serial_number,
            ])->all(),
            'unexpected' => $unexpectedNumbers->all(),
        ];
    }

    /** POS persists made-to-order lines with a `__MTO__` note prefix. */
    private static function isMto(object $item): bool
    {
        return str_starts_with((string) ($item->notes ?? ''), '__MTO__');
    }
}
