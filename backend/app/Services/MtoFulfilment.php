<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\ProductSerial;
use Illuminate\Support\Facades\DB;

/**
 * A customer's made-to-order garment, from the bench to the customer's hands
 * (owner decision 2026-10-05, option a: "into stock at completion, out at
 * collection").
 *
 *   completion  → on_hand +qty (the 'production' row ProductionController
 *                 writes) and HELD: reserved +qty, so the garment is counted and
 *                 valued but cannot be sold to anyone else; its serials are SOLD
 *                 to the customer's order.                    ledger: mto_hold
 *   collection  → the customer's order completes (POS dispatch, or the admin
 *                 status change): on_hand −qty, reserved −qty; serials
 *                 DISPATCHED.                                 ledger: mto_collected
 *   cancel/void → before collection the hold is lifted and the garment becomes
 *                 ordinary shop stock (mto_release); after collection the
 *                 garment comes back onto the shelf (mto_return), mirroring how
 *                 a voided sale restores its stocked lines.
 *
 * Before this, completion added the garment as free stock and nothing ever took
 * it out: every finished customer garment would have stayed sellable forever.
 *
 * State lives in the inventory ledger, one row per step, all referencing the
 * production order — so every step is idempotent (it checks the ledger under a
 * row lock before writing) and auditable without a schema change.
 *
 * Stock-for-the-shop jobs (is_customer_order = false, or no customer order)
 * never pass through here.
 */
final class MtoFulfilment
{
    public const HOLD      = 'mto_hold';
    public const COLLECTED = 'mto_collected';
    public const RELEASED  = 'mto_release';
    public const RETURNED  = 'mto_return';

    public static function isCustomerJob(ProductionOrder $po): bool
    {
        return (bool) $po->is_customer_order && (bool) $po->customer_order_id;
    }

    /**
     * The customer's order line this job is producing.
     *
     * Linked jobs answer directly. Older jobs were raised without the link (POS
     * pending orders, manual raises), so they are matched on the customer's
     * order by product (and variant): a line already pointing at this job,
     * else an unlinked made-to-order line, else an unlinked line of the same
     * product. Exactly one candidate in the first non-empty tier is a match;
     * none or several is refused — a garment is never held against a guess
     * (owner decision 3).
     *
     * @return array{0: ?OrderItem, 1: ?string} [line, refusal message]
     */
    public static function resolveLine(ProductionOrder $po): array
    {
        if ($po->order_item_id) {
            $line = OrderItem::find($po->order_item_id);
            if ($line && (int) $line->order_id === (int) $po->customer_order_id) {
                return [$line, null];
            }

            return [null, "This job is linked to order line #{$po->order_item_id}, which is not on its customer's order."];
        }

        $lines = OrderItem::where('order_id', $po->customer_order_id)
            ->where('product_id', $po->product_id)
            ->when($po->product_variant_id, fn ($q) => $q->where(
                fn ($qq) => $qq->where('product_variant_id', $po->product_variant_id)->orWhereNull('product_variant_id'),
            ))
            ->orderBy('id')
            ->get();

        $tiers = [
            $lines->filter(fn ($l) => (int) $l->production_order_id === (int) $po->id),
            $lines->filter(fn ($l) => $l->production_order_id === null && self::isMadeToOrder($l)),
            $lines->filter(fn ($l) => $l->production_order_id === null),
        ];

        foreach ($tiers as $tier) {
            if ($tier->count() === 1) {
                return [$tier->first(), null];
            }
            if ($tier->count() > 1) {
                return [null, "The customer's order has {$tier->count()} matching lines for this product, so the system cannot tell which one this garment is for. Link the job to its line first."];
            }
        }

        return [null, "No line on the customer's order matches this job's product, so the finished garment cannot be held for them."];
    }

    /** Record the link both ways (inside the caller's transaction). */
    public static function link(ProductionOrder $po, OrderItem $line): void
    {
        if ((int) $po->order_item_id !== (int) $line->id) {
            $po->forceFill(['order_item_id' => $line->id])->save();
        }
        if ((int) $line->production_order_id !== (int) $po->id || ! $line->requires_production) {
            $line->forceFill(['production_order_id' => $po->id, 'requires_production' => true])->save();
        }
    }

    /** Where a customer's garment is held: the outlet the order was taken at (owner decision 2). */
    public static function holdOutletId(ProductionOrder $po, ?Order $sale): ?int
    {
        return $sale?->outlet_id ?? $po->target_outlet_id ?? $po->outlet_id;
    }

    /**
     * Hold the just-stocked garment for its customer. Call inside the
     * completion transaction, after the 'production' stock-in and the serials'
     * move into stock. If the customer's order is already complete (a POS sale
     * can be dispatched before its garment is finished), the garment is handed
     * over at once.
     */
    public static function hold(ProductionOrder $po, InventoryItem $row, int $qty, ?int $userId): void
    {
        $sale = Order::find($po->customer_order_id);
        $row  = InventoryItem::whereKey($row->id)->lockForUpdate()->firstOrFail();

        if (self::stepsOf($po)->contains(self::HOLD)) {
            return;
        }

        $row->reserveUnits($qty);
        self::ledger($row, self::HOLD, 0, $po, $userId,
            "Held for {$sale?->order_number} ({$qty} unit(s)) - not for sale");

        ProductSerial::where('production_order_id', $po->id)
            ->where('status', ProductSerial::IN_STOCK)
            ->update([
                'status'     => ProductSerial::SOLD,
                'order_id'   => $po->customer_order_id,
                'sold_at'    => now(),
                'updated_at' => now(),
            ]);

        if ($sale && $sale->status === 'completed') {
            self::collect($po, $sale, $userId);
        }
    }

    /** The customer's order is complete: every held garment on it leaves stock. */
    public static function collectForOrder(Order $sale, ?int $userId): void
    {
        foreach (self::customerJobsOf($sale) as $po) {
            self::collect($po, $sale, $userId);
        }
    }

    /** The customer's order is cancelled or voided: lift holds, or bring collected garments back. */
    public static function releaseForOrder(Order $sale, ?int $userId): void
    {
        foreach (self::customerJobsOf($sale) as $po) {
            $po    = ProductionOrder::whereKey($po->id)->lockForUpdate()->first();
            $steps = self::stepsOf($po);
            if (! $steps->contains(self::HOLD) || $steps->contains(self::RELEASED) || $steps->contains(self::RETURNED)) {
                continue;
            }

            $row = InventoryItem::whereKey(self::holdRowId($po))->lockForUpdate()->first();
            if (! $row) {
                throw new \RuntimeException("Held stock row for production order {$po->order_number} no longer exists.");
            }
            $qty = (int) $po->quantity;

            if ($steps->contains(self::COLLECTED)) {
                // Handed over, then the sale was voided: the garment is back.
                $before = (int) $row->quantity_on_hand;
                $row->quantity_on_hand = $before + $qty;
                $row->save();
                self::ledger($row, self::RETURNED, $qty, $po, $userId,
                    "Returned to stock: {$sale->order_number} was {$sale->status} after collection", $before);
            } else {
                $row->release($qty);
                self::ledger($row, self::RELEASED, 0, $po, $userId,
                    "Hold lifted: {$sale->order_number} was {$sale->status} - now ordinary stock");
            }

            ProductSerial::where('production_order_id', $po->id)
                ->where('order_id', $sale->id)
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
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function collect(ProductionOrder $po, Order $sale, ?int $userId): void
    {
        $po    = ProductionOrder::whereKey($po->id)->lockForUpdate()->first();
        $steps = self::stepsOf($po);
        if (! $steps->contains(self::HOLD) || $steps->contains(self::COLLECTED) || $steps->contains(self::RELEASED)) {
            return;
        }

        $row = InventoryItem::whereKey(self::holdRowId($po))->lockForUpdate()->first();
        $qty = (int) $po->quantity;

        // Fail loudly rather than clamp: a held garment that is not on the
        // shelf is a stock error someone must look at, not a silent zero.
        if (! $row || (int) $row->quantity_on_hand < $qty || (int) $row->quantity_reserved < $qty) {
            throw new \RuntimeException(
                "Cannot hand over {$po->order_number}: the held stock is missing "
                . "(on hand {$row?->quantity_on_hand}, reserved {$row?->quantity_reserved}, needed {$qty})."
            );
        }

        $before = (int) $row->quantity_on_hand;
        $row->quantity_on_hand  = $before - $qty;
        $row->quantity_reserved = (int) $row->quantity_reserved - $qty;
        $row->save();
        self::ledger($row, self::COLLECTED, -$qty, $po, $userId,
            "Collected by the customer: {$sale->order_number}", $before);

        ProductSerial::where('production_order_id', $po->id)
            ->where('order_id', $sale->id)
            ->where('status', ProductSerial::SOLD)
            ->update([
                'status'        => ProductSerial::DISPATCHED,
                'dispatched_at' => now(),
                'updated_at'    => now(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, ProductionOrder> */
    private static function customerJobsOf(Order $sale)
    {
        return ProductionOrder::where('customer_order_id', $sale->id)
            ->where('is_customer_order', true)
            ->where('status', 'completed')
            ->orderBy('id')
            ->get();
    }

    /** The ledger steps already taken for a job. */
    private static function stepsOf(ProductionOrder $po)
    {
        return InventoryTransaction::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $po->id)
            ->whereIn('transaction_type', [self::HOLD, self::COLLECTED, self::RELEASED, self::RETURNED])
            ->pluck('transaction_type');
    }

    private static function holdRowId(ProductionOrder $po): ?int
    {
        $id = InventoryTransaction::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $po->id)
            ->where('transaction_type', self::HOLD)
            ->value('inventory_item_id');

        return $id === null ? null : (int) $id;
    }

    private static function ledger(
        InventoryItem $row,
        string $type,
        int $change,
        ProductionOrder $po,
        ?int $userId,
        string $notes,
        ?int $before = null,
    ): void {
        $before ??= (int) $row->quantity_on_hand;
        InventoryTransaction::create([
            'inventory_item_id' => $row->id,
            'transaction_type'  => $type,
            'reference_type'    => ProductionOrder::class,
            'reference_id'      => $po->id,
            'quantity_change'   => $change,
            'quantity_before'   => $before,
            'quantity_after'    => $before + $change,
            'notes'             => mb_substr($notes, 0, 1000),
            'created_by'        => $userId ?? auth()->id(),
        ]);
    }

    /** POS marks made-to-order lines with a `__MTO__` note prefix; other channels set requires_production. */
    private static function isMadeToOrder(OrderItem $line): bool
    {
        return str_starts_with((string) ($line->notes ?? ''), '__MTO__') || (bool) $line->requires_production;
    }
}
