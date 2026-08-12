<?php

namespace App\Services;

use App\Exceptions\OrderLineEditException;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add / edit / remove the line items of an EXISTING order, atomically.
 *
 * This is the money path. Everything downstream of a line — subtotal, per-line
 * tax, the order total, what the customer still owes, what is on the shelf, and
 * what the sale cost us — has to move together or the receipt starts lying. So
 * the whole operation is one desired-state submit inside one transaction:
 * the caller sends the line set it wants, the server works out the diff and
 * derives every figure itself.
 *
 * PosController::updatePendingOrder is the reference for doing this safely, and
 * this borrows its model — release the old stock, draw the new, recompute from
 * the resulting line set, preserve the COGS snapshot. It differs in two ways
 * that matter, both because that method is gated to PENDING, never-paid,
 * never-returned, never-produced orders and this one is not:
 *
 *   1. It DELETES and recreates every line. We cannot: an order_item is
 *      referenced by return_items (a non-nullable FK) and production_orders,
 *      and it carries a cost snapshot, a price-adjustment audit trail and the
 *      inventory row it drew from. So we diff by id and only touch what moved.
 *   2. It never has to reconcile payments, because there are none. We always
 *      re-derive payment_status from settled payments against the new total,
 *      and surface an overpayment instead of leaving a paid receipt quietly
 *      disagreeing with its own payments.
 *
 * What the caller may send per line:
 *   ['id' => 12, 'quantity' => 3, 'unit_price' => 500, 'discount_amount' => 0]
 *     → an existing line: quantity / price / discount only. Changing the
 *       PRODUCT of a line is deliberately not possible — that is a removal and
 *       an addition, and modelling it that way keeps the cost snapshot honest.
 *   ['product_id' => 9, 'product_variant_id' => null, 'quantity' => 1]
 *     → a new line. unit_price is optional; omitted, it is resolved from the
 *       catalogue in the order's currency.
 *   Any existing line the caller omits is removed.
 *
 * The caller never sends a total. Subtotal, per-line tax, order tax, and the
 * order total are all derived here.
 */
class OrderLineEditor
{
    /** Statuses where the order is closed and its lines are frozen. */
    public const CLOSED_STATUSES = ['cancelled', 'voided', 'refunded'];

    /**
     * Apply a desired line set to an order.
     *
     * @param  array<int,array<string,mixed>>  $desired
     * @param  array{confirm_paid_change?:bool, confirm_shipped_change?:bool, reason?:?string}  $opts
     * @return array<string,mixed>  the recalculated money picture
     *
     * @throws ValidationException  every refusal, carrying a machine-readable
     *                              `reason` the UI keys off.
     */
    public function apply(Order $order, array $desired, array $opts = [], ?User $actor = null): array
    {
        $order->loadMissing(['items', 'payments']);

        $this->guardOrderIsOpen($order);

        [$keep, $add, $removeIds] = $this->diff($order, $desired);

        $this->guardProtectedLines($order, $keep, $removeIds);
        $this->guardConfirmations($order, $opts);

        $before = [
            'subtotal'       => (float) $order->subtotal,
            'tax_amount'     => (float) $order->tax_amount,
            'total_amount'   => (float) $order->total_amount,
            'payment_status' => $order->payment_status,
            'items'          => $order->items->map(fn ($i) => $this->snapshotLine($i))->values()->all(),
        ];

        return DB::transaction(function () use ($order, $keep, $add, $removeIds, $opts, $actor, $before) {
            $taxInclusive = $this->taxInclusiveFor($order);
            $stockMode    = $this->stockMode($order);

            // An order that draws from the shelf may only gain lines that HAVE
            // a shelf to draw from. Silently adding an untracked line to a
            // stock-backed order is how inventory quietly drifts.
            if ($stockMode !== 'none') {
                foreach ($add as $row) {
                    if (!$row['inventory_item_id']) {
                        $this->refuse(
                            "\"{$row['product_name']}\" has no stock record at this outlet, so it cannot be "
                            . 'added to an order that draws from inventory.',
                            'no_inventory_row',
                        );
                    }
                }
            }

            // ── 1. Net stock movement per inventory row ───────────────────────
            // Netting first means swapping 3 of a product for 2 asks the shelf
            // for -1, not for +2 on a shelf that may not have it. Same reason
            // updatePendingOrder returns everything before it draws anything.
            $deltas = $this->stockDeltas($order, $keep, $add, $removeIds);

            // ── 2. Mutate the lines ───────────────────────────────────────────
            $removed = [];
            foreach ($removeIds as $id) {
                $line      = $order->items->firstWhere('id', $id);
                $removed[] = $this->snapshotLine($line);
                OrderItem::whereKey($id)->delete();
            }

            $updated = [];
            foreach ($keep as $row) {
                /** @var OrderItem $line */
                $line = $row['line'];
                $calc = $this->lineFigures(
                    (float) $row['unit_price'],
                    (int) $row['quantity'],
                    (float) $row['discount_amount'],
                    (int) ($line->product_id ?? 0),
                    $taxInclusive,
                );

                $wasChanged = (float) $line->unit_price !== (float) $row['unit_price']
                    || (int) $line->quantity !== (int) $row['quantity']
                    || (float) $line->discount_amount !== (float) $row['discount_amount'];

                if ($wasChanged) {
                    $updated[] = ['from' => $this->snapshotLine($line), 'to' => [
                        'id'              => $line->id,
                        'product_name'    => $line->product_name,
                        'quantity'        => (int) $row['quantity'],
                        'unit_price'      => (float) $row['unit_price'],
                        'discount_amount' => (float) $row['discount_amount'],
                    ]];
                }

                // COGS: cost_price / cost_source are NOT touched. The line is
                // still the same product, so the cost it was booked at when it
                // was sold is still the cost it was booked at.
                $line->update([
                    'quantity'        => (int) $row['quantity'],
                    'unit_price'      => round((float) $row['unit_price'], 2),
                    'discount_amount' => round((float) $row['discount_amount'], 2),
                    'tax_amount'      => $calc['tax_amount'],
                    'total_price'     => $calc['total_price'],
                ]);
            }

            $added = [];
            foreach ($add as $row) {
                $calc = $this->lineFigures(
                    (float) $row['unit_price'],
                    (int) $row['quantity'],
                    (float) $row['discount_amount'],
                    (int) $row['product_id'],
                    $taxInclusive,
                );

                $line = OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $row['product_id'],
                    'product_variant_id' => $row['product_variant_id'],
                    'product_name'       => $row['product_name'],
                    'variant_name'       => $row['variant_name'],
                    'sku'                => $row['sku'],
                    'quantity'           => (int) $row['quantity'],
                    'unit_price'         => round((float) $row['unit_price'], 2),
                    'discount_amount'    => round((float) $row['discount_amount'], 2),
                    'tax_amount'         => $calc['tax_amount'],
                    'total_price'        => $calc['total_price'],
                    // COGS: a brand-new line is costed at today's resolved cost,
                    // exactly as if it had been rung up now — which it was.
                    ...app(CostResolver::class)->columns($row['product_id'], $row['product_variant_id']),
                    // Pin the shelf row this line draws from, so a later
                    // commit / void / return acts on exactly that row.
                    'inventory_item_id'  => $row['inventory_item_id'],
                ]);

                $added[] = $this->snapshotLine($line);
            }

            // ── 3. Move the stock ─────────────────────────────────────────────
            $stockMoves = $this->applyStockDeltas($order, $deltas, $stockMode, $actor?->id);

            // ── 4. Recalculate every figure from the resulting line set ───────
            $totals = $this->recalculateTotals($order, $taxInclusive);

            // ── 5. Reconcile the money already collected ──────────────────────
            $order->refresh();
            $paid = round($order->totalPaid(), 2);
            $order->syncPaymentStatus();
            $order->refresh();

            $newTotal = (float) $order->total_amount;
            $overpaid = $paid > $newTotal + 0.01 ? round($paid - $newTotal, 2) : 0.0;
            $balance  = round(max(0, $newTotal - $paid), 2);

            // A receipt whose collected money now exceeds what is owed must NOT
            // sit there looking settled. syncPaymentStatus correctly keeps it
            // 'paid' (nothing is outstanding), so the refund owed is recorded on
            // the order itself where staff will see it, as well as in the audit
            // trail and the response.
            if ($overpaid > 0) {
                $note = 'Line items edited — order total is now '
                    . number_format($newTotal, 2) . ' ' . ($order->currency_code ?? '')
                    . ' but ' . number_format($paid, 2) . ' has been collected. '
                    . 'REFUND DUE: ' . number_format($overpaid, 2) . '.';
                $order->update(['notes' => ($order->notes ? $order->notes . "\n\n" : '') . $note]);
            }

            // Serial-tracked goods: reconcile the sold set to the new lines.
            // syncSoldForOrder is idempotent and claims/releases only the
            // difference. Only meaningful once the goods have actually left.
            if ($stockMode === 'committed') {
                ProductSerialService::syncSoldForOrder($order->fresh(['items']));
            }

            ActivityLogService::log('order_items_edited', $order, [
                'order_number'           => $order->order_number,
                'reason'                 => $opts['reason'] ?? null,
                'added'                  => $added,
                'updated'                => $updated,
                'removed'                => $removed,
                'old_subtotal'           => $before['subtotal'],
                'new_subtotal'           => (float) $order->subtotal,
                'old_tax_amount'         => $before['tax_amount'],
                'new_tax_amount'         => (float) $order->tax_amount,
                'old_total'              => $before['total_amount'],
                'new_total'              => $newTotal,
                'old_payment_status'     => $before['payment_status'],
                'new_payment_status'     => $order->payment_status,
                'amount_paid'            => $paid,
                'balance'                => $balance,
                'overpaid'               => $overpaid,
                'discount_clamped'       => $totals['discount_clamped'],
                'stock_mode'             => $stockMode,
                'stock_moves'            => $stockMoves,
            ], null, $actor);

            return [
                'subtotal'               => (float) $order->subtotal,
                'discount_amount'        => (float) $order->discount_amount,
                'discount_clamped'       => $totals['discount_clamped'],
                'tax_amount'             => (float) $order->tax_amount,
                'shipping_amount'        => (float) $order->shipping_amount,
                'total_amount'           => $newTotal,
                'amount_paid'            => $paid,
                'balance'                => $balance,
                'overpaid'               => $overpaid,
                'previous_payment_status' => $before['payment_status'],
                'payment_status'         => $order->payment_status,
                'stock_mode'             => $stockMode,
                'added'                  => count($added),
                'updated'                => count($updated),
                'removed'                => count($removed),
            ];
        });
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    private function guardOrderIsOpen(Order $order): void
    {
        if (in_array($order->status, self::CLOSED_STATUSES, true)) {
            $this->refuse(
                'This order is ' . $order->status . '. A closed order\'s lines cannot be changed.',
                'order_closed',
            );
        }
    }

    /**
     * Lines that other live records depend on. These are hard blocks, not
     * confirmations: the correct move is to unwind the dependent record first
     * (cancel the production order, process the return), and doing that from
     * here would be doing it silently.
     *
     * @param  array<int,array<string,mixed>>  $keep
     * @param  int[]  $removeIds
     */
    private function guardProtectedLines(Order $order, array $keep, array $removeIds): void
    {
        $returnedQty = DB::table('return_items')
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->selectRaw('order_item_id, SUM(quantity) AS qty')
            ->groupBy('order_item_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->order_item_id => (int) $r->qty]);

        foreach ($removeIds as $id) {
            $line = $order->items->firstWhere('id', $id);
            if ($line->production_order_id) {
                $this->refuse(
                    "\"{$line->product_name}\" is linked to a production order and cannot be removed. "
                    . 'Cancel the production order first.',
                    'line_in_production',
                );
            }
            if (($returnedQty[$id] ?? 0) > 0) {
                $this->refuse(
                    "\"{$line->product_name}\" has been returned against and cannot be removed.",
                    'line_returned',
                );
            }
        }

        foreach ($keep as $row) {
            /** @var OrderItem $line */
            $line = $row['line'];
            $qty  = (int) $row['quantity'];

            if ($line->production_order_id && $qty !== (int) $line->quantity) {
                $this->refuse(
                    "\"{$line->product_name}\" is in production — its quantity is fixed by the production "
                    . 'order. Amend the production order instead.',
                    'line_in_production',
                );
            }

            $returned = (int) ($returnedQty[$line->id] ?? 0);
            if ($returned > 0 && $qty < $returned) {
                $this->refuse(
                    "\"{$line->product_name}\" cannot drop below the {$returned} unit(s) already returned against it.",
                    'line_returned',
                );
            }
        }
    }

    /**
     * The two situations where the edit is legitimate but must not be a
     * one-click accident: money has already been collected, or the goods have
     * already been shipped. Both are recoverable and both are real correction
     * scenarios, so they ask for an explicit acknowledgement rather than
     * refusing outright.
     *
     * @param  array<string,mixed>  $opts
     */
    private function guardConfirmations(Order $order, array $opts): void
    {
        if ($order->totalPaid() > 0.01 && empty($opts['confirm_paid_change'])) {
            $this->refuse(
                'This order has payments recorded against it. Editing its lines changes what is owed — '
                . 'confirm to continue.',
                'confirm_paid_change',
            );
        }

        if ($order->shipments()->exists() && empty($opts['confirm_shipped_change'])) {
            $this->refuse(
                'A shipment already exists for this order. Editing its lines will not match what was '
                . 'dispatched — confirm to continue.',
                'confirm_shipped_change',
            );
        }
    }

    // ── Diff ──────────────────────────────────────────────────────────────────

    /**
     * Split the desired set into keep-with-changes / add / remove.
     *
     * @param  array<int,array<string,mixed>>  $desired
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:int[]}
     */
    private function diff(Order $order, array $desired): array
    {
        $existing = $order->items->keyBy('id');
        $keep     = [];
        $add      = [];
        $seen     = [];

        foreach ($desired as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;

            if ($id > 0) {
                $line = $existing->get($id);
                if (!$line) {
                    $this->refuse("Line #{$id} is not on this order.", 'unknown_line');
                }
                if (in_array($id, $seen, true)) {
                    $this->refuse("Line #{$id} was sent twice.", 'duplicate_line');
                }
                $seen[]  = $id;
                $keep[]  = [
                    'line'            => $line,
                    'quantity'        => (int) $row['quantity'],
                    'unit_price'      => array_key_exists('unit_price', $row) && $row['unit_price'] !== null
                        ? (float) $row['unit_price']
                        : (float) $line->unit_price,
                    'discount_amount' => array_key_exists('discount_amount', $row) && $row['discount_amount'] !== null
                        ? (float) $row['discount_amount']
                        : (float) $line->discount_amount,
                ];
                continue;
            }

            $add[] = $this->resolveNewLine($order, $row);
        }

        $removeIds = $existing->keys()
            ->reject(fn ($id) => in_array((int) $id, $seen, true))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [$keep, $add, $removeIds];
    }

    /**
     * Fill in everything a new line needs that the client did not send — the
     * product's name, sku, catalogue price in the order's currency, and the
     * shelf row it will draw from.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function resolveNewLine(Order $order, array $row): array
    {
        $variantId = isset($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;
        $variant   = $variantId ? ProductVariant::with('product.translations')->find($variantId) : null;
        $productId = $variant?->product_id ?? (isset($row['product_id']) ? (int) $row['product_id'] : 0);

        if (!$productId) {
            $this->refuse('A new line needs a product.', 'missing_product');
        }

        $product = $variant?->product ?? Product::with('translations')->find($productId);
        if (!$product) {
            $this->refuse('Product not found.', 'missing_product');
        }

        $name = $product->translations->firstWhere('language_code', 'en')?->name
            ?? $product->translations->first()?->name
            ?? ('Product #' . $productId);

        $unitPrice = array_key_exists('unit_price', $row) && $row['unit_price'] !== null
            ? (float) $row['unit_price']
            : $this->cataloguePrice($productId, $variantId, $order->currency_code ?? 'KES');

        $probe = (object) [
            'product_id'         => $productId,
            'product_variant_id' => $variantId,
            'inventory_item_id'  => null,
            'notes'              => null,
        ];
        $inventory = PosInventoryService::inventoryFor($probe, $order->outlet_id);

        return [
            'product_id'         => $productId,
            'product_variant_id' => $variantId,
            'product_name'       => $name,
            'variant_name'       => $variant?->variant_name,
            'sku'                => $variant?->sku ?? $product->sku,
            'quantity'           => (int) $row['quantity'],
            'unit_price'         => $unitPrice,
            'discount_amount'    => isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0,
            'inventory_item_id'  => $inventory?->id,
        ];
    }

    private function cataloguePrice(int $productId, ?int $variantId, string $currency): float
    {
        $q = ProductPrice::query()->where('product_id', $productId);
        $variantId
            ? $q->where('product_variant_id', $variantId)
            : $q->whereNull('product_variant_id');

        $row = (clone $q)->where('currency_code', $currency)->first() ?? (clone $q)->first();

        return $row ? (float) $row->regular_price : 0.0;
    }

    // ── Stock ─────────────────────────────────────────────────────────────────

    /**
     * How this order relates to the shelf.
     *
     *   committed → the goods physically left (quantity_on_hand was deducted)
     *   reserved  → only a reservation is held (quantity_on_hand untouched)
     *   none      → this order never drew inventory
     *
     * The three timestamps are authoritative, but they are not universal: the
     * storefront checkout path deducts quantity_on_hand directly and sets none
     * of them. So when they are silent we ask the ledger — an inventory
     * transaction of type 'sale' against this order is proof the goods moved.
     */
    private function stockMode(Order $order): string
    {
        if ($order->stock_unwound_at) {
            return 'none';
        }
        if ($order->stock_committed_at) {
            return 'committed';
        }
        if ($order->stock_reserved_at) {
            return 'reserved';
        }

        $drewStock = InventoryTransaction::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('transaction_type', 'sale')
            ->exists();

        return $drewStock ? 'committed' : 'none';
    }

    /**
     * Net units to draw (+) or return (-) per inventory row.
     *
     * @param  array<int,array<string,mixed>>  $keep
     * @param  array<int,array<string,mixed>>  $add
     * @param  int[]  $removeIds
     * @return array<int,int>  inventory_item_id => net delta
     */
    private function stockDeltas(Order $order, array $keep, array $add, array $removeIds): array
    {
        $deltas = [];
        $bump   = function (?InventoryItem $inv, int $delta) use (&$deltas) {
            if (!$inv || $delta === 0) {
                return;
            }
            $deltas[$inv->id] = ($deltas[$inv->id] ?? 0) + $delta;
        };

        foreach ($removeIds as $id) {
            $line = $order->items->firstWhere('id', $id);
            $bump($this->inventoryForLine($order, $line), -(int) $line->quantity);
        }

        foreach ($keep as $row) {
            /** @var OrderItem $line */
            $line  = $row['line'];
            $delta = (int) $row['quantity'] - (int) $line->quantity;
            $bump($this->inventoryForLine($order, $line), $delta);
        }

        foreach ($add as $row) {
            if (!$row['inventory_item_id']) {
                continue;
            }
            $bump(InventoryItem::find($row['inventory_item_id']), (int) $row['quantity']);
        }

        return array_filter($deltas, fn ($d) => $d !== 0);
    }

    /** Made-to-order lines never came off a shelf, so they never go back on one. */
    private function inventoryForLine(Order $order, OrderItem $line): ?InventoryItem
    {
        if (PosInventoryService::isMtoLine($line)) {
            return null;
        }

        return PosInventoryService::inventoryFor($line, $order->outlet_id);
    }

    /**
     * @param  array<int,int>  $deltas
     * @return array<int,array<string,mixed>>
     */
    private function applyStockDeltas(Order $order, array $deltas, string $mode, ?int $actorId): array
    {
        if ($mode === 'none' || empty($deltas)) {
            return [];
        }

        $moves = [];
        foreach ($deltas as $inventoryId => $delta) {
            /** @var InventoryItem|null $inv */
            $inv = InventoryItem::whereKey($inventoryId)->lockForUpdate()->first();
            if (!$inv) {
                continue;
            }

            if ($delta > 0 && $inv->quantity_available < $delta) {
                $this->refuse(
                    'Insufficient stock to add those units. Available: ' . $inv->quantity_available . '.',
                    'insufficient_stock',
                );
            }

            if ($mode === 'committed') {
                // The goods have already left for this order, so a change is a
                // physical movement now: more units leave, or units come back.
                $inv->adjustQuantity(
                    -$delta,
                    $delta > 0 ? 'sale' : 'void_return',
                    Order::class,
                    $order->id,
                    $actorId,
                );
            } else {
                $delta > 0 ? $inv->reserveUnits($delta) : $inv->release(-$delta);
            }

            $moves[] = ['inventory_item_id' => $inv->id, 'delta' => -$delta, 'mode' => $mode];
        }

        return $moves;
    }

    // ── Arithmetic ────────────────────────────────────────────────────────────

    /**
     * Whether this order's prices already include tax.
     *
     * The order's OWN stored flag wins: it is what the receipt was computed
     * under, and re-reading the global setting would silently reprice a
     * historical order if the shop ever flipped it. Only an order that never
     * recorded one falls back to the current setting.
     */
    private function taxInclusiveFor(Order $order): bool
    {
        $raw = $order->getAttributes()['prices_include_tax'] ?? null;

        return $raw === null
            ? TaxCalculationService::isTaxInclusive()
            : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * One line's tax and total.
     *
     * Tax is computed on unit_price × quantity, BEFORE the line discount —
     * matching adjustItemPrice and updatePendingOrder, the two writers that
     * already maintain these columns. Doing anything cleverer here would make
     * an edited receipt disagree with the one it replaced.
     *
     * @return array{tax_amount:float,total_price:float,line_subtotal:float}
     */
    private function lineFigures(float $unitPrice, int $qty, float $discount, int $productId, bool $taxInclusive): array
    {
        $calc         = TaxCalculationService::calculateLine($unitPrice, $qty, $productId, $taxInclusive);
        $lineTax      = round((float) $calc['tax_amount'], 2);
        $lineSubtotal = ($unitPrice * $qty) - $discount;

        return [
            'tax_amount'    => $lineTax,
            'total_price'   => round($taxInclusive ? $lineSubtotal : $lineSubtotal + $lineTax, 2),
            'line_subtotal' => round($lineSubtotal, 2),
        ];
    }

    /**
     * Derive subtotal / tax / total from whatever lines the order now has, and
     * persist them. The order-level discount and shipping ride along untouched,
     * except that a cart discount larger than the surviving subtotal is clamped
     * rather than pushing the total negative — that clamp is this editor's own
     * requirement, since removing lines is the only way to shrink a subtotal out
     * from under a discount that was sized against it.
     *
     * The arithmetic itself lives in OrderTotals, alongside every other writer
     * that persists these three columns.
     *
     * @return array{discount_clamped:bool}
     */
    private function recalculateTotals(Order $order, bool $taxInclusive): array
    {
        // Read the lines explicitly rather than through $order->items, which may
        // still hold the pre-edit relation.
        $totals = OrderTotals::forOrder(
            $order,
            $taxInclusive,
            OrderItem::where('order_id', $order->id)->get(),
            clampDiscount: true,
        );

        $totals->persistTo($order, [
            'discount_amount'    => $totals->discount,
            'prices_include_tax' => $totals->pricesIncludeTax,
        ]);

        return ['discount_clamped' => $totals->discountClamped];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function snapshotLine(OrderItem $line): array
    {
        return [
            'id'              => $line->id,
            'product_id'      => $line->product_id,
            'product_name'    => $line->product_name,
            'sku'             => $line->sku,
            'quantity'        => (int) $line->quantity,
            'unit_price'      => (float) $line->unit_price,
            'discount_amount' => (float) $line->discount_amount,
            'total_price'     => (float) $line->total_price,
        ];
    }

    /**
     * Every refusal comes back as a 422 with a stable `reason`, so the UI can
     * tell "needs a confirmation" from "will never be allowed".
     */
    private function refuse(string $message, string $reason): never
    {
        throw new OrderLineEditException($message, $reason);
    }
}
