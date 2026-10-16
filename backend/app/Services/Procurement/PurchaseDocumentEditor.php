<?php

namespace App\Services\Procurement;

use App\Models\DocumentRevision;
use App\Models\GoodsReceivedNote;
use App\Models\GrnItem;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProductSerialService;
use App\Support\ReportingCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's edits of issued procurement documents (owner, 2026-10-16).
 *
 * Decided with the owner:
 *  - Every edit is a REVISION: the document as it was is kept, read-only
 *    (DocumentRevision), with who, when and why; the document shows "Rev N".
 *  - Goods already received are CORRECTED, never rewritten: stock moves by the
 *    difference only, dated now, against the goods receipt, with the reason.
 *    A price correction updates the latest-cost fields that receiving sets —
 *    and only when this line is still the item's latest receipt. The cost of
 *    goods already sold is the cost snapshotted on each sold line
 *    (order_items.cost_price, App\Support\CostBasis) and does not move.
 *  - The owner is the final approver: his edit keeps an approved order
 *    approved, recorded as such. A PO still WAITING for approval goes back to
 *    draft as before — its pending request was signed against what it was.
 */
class PurchaseDocumentEditor
{
    // ── Purchase order ───────────────────────────────────────────────────────

    /**
     * @param array $data validated: reason, items[], and optional header fields
     * @return array{purchase_order: PurchaseOrder, changes: string[], notes: string[]}
     */
    public function revisePurchaseOrder(PurchaseOrder $po, array $data, User $owner): array
    {
        return DB::transaction(function () use ($po, $data, $owner) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            $po->load('items', 'supplier:id,name');

            if ($po->status === 'cancelled') {
                $this->refuse('items', 'A cancelled purchase order is not edited.');
            }

            $before   = $this->poSnapshot($po);
            $changes  = [];
            $notes    = [];
            $existing = $po->items->keyBy('id');

            // ── Lines ────────────────────────────────────────────────────────
            $seenIds     = [];
            $variantSeen = [];
            $plan        = [];
            foreach ($data['items'] as $i => $in) {
                $type      = $in['type'];
                $itemId    = (int) $in['item_id'];
                $variantId = isset($in['product_variant_id']) ? (int) $in['product_variant_id'] : null;
                $qtyH      = self::hundredths($in['quantity']);
                $price     = round((float) $in['unit_price'], 2);

                if ($type === 'product' && $qtyH % 100 !== 0) {
                    $this->refuse("items.$i.quantity", 'Products are ordered in whole pieces.');
                }
                if ($type === 'material' && $variantId !== null) {
                    $this->refuse("items.$i.product_variant_id", 'A material line cannot name a product variant.');
                }
                $label = $this->labelFor($type, $itemId, $variantId, "items.$i");

                if ($variantId !== null) {
                    if (isset($variantSeen[$variantId])) {
                        $this->refuse("items.$i", "{$label} is on the order twice. Keep one line per variant.");
                    }
                    $variantSeen[$variantId] = true;
                }

                $line = null;
                if (!empty($in['id'])) {
                    $line = $existing->get((int) $in['id']);
                    if (!$line) {
                        $this->refuse("items.$i.id", 'That line is not on this purchase order.');
                    }
                    $seenIds[] = $line->id;
                    $receivedH = self::hundredths($line->quantity_received);
                    $sameItem  = $line->item_type === $type
                        && (int) ($type === 'product' ? $line->product_id : $line->material_id) === $itemId
                        && (int) $line->product_variant_id === (int) $variantId;
                    if ($receivedH > 0 && !$sameItem) {
                        $this->refuse("items.$i.item_id", "{$line->description}: goods have been received on this line, so it stays the same item. Add a new line instead.");
                    }
                    if ($qtyH < $receivedH) {
                        $this->refuse("items.$i.quantity", "{$line->description}: " . ($receivedH / 100) . ' already received. Correct the goods receipt first to go lower.');
                    }
                }
                $plan[] = compact('line', 'type', 'itemId', 'variantId', 'qtyH', 'price', 'label');
            }

            foreach ($existing as $line) {
                if (in_array($line->id, $seenIds, true)) {
                    continue;
                }
                if (self::hundredths($line->quantity_received) > 0) {
                    $this->refuse('items', "{$line->description}: goods have been received on this line, so it cannot be removed. Correct the goods receipt first.");
                }
                $changes[] = "Removed {$line->description} (" . self::qty($line->quantity) . " × {$line->unit_price})";
                $line->delete();
            }

            $subtotal = 0.0;
            foreach ($plan as $p) {
                $qty   = $p['qtyH'] / 100;
                $total = round($qty * $p['price'], 2);
                $subtotal += $total;

                if ($p['line'] === null) {
                    PurchaseOrderItem::create([
                        'purchase_order_id'  => $po->id,
                        'item_type'          => $p['type'],
                        'product_id'         => $p['type'] === 'product' ? $p['itemId'] : null,
                        'product_variant_id' => $p['type'] === 'product' ? $p['variantId'] : null,
                        'material_id'        => $p['type'] === 'material' ? $p['itemId'] : null,
                        'description'        => $p['label'],
                        'quantity'           => $qty,
                        'quantity_received'  => 0,
                        'unit_price'         => $p['price'],
                        'tax_amount'         => 0,
                        'total_price'        => $total,
                    ]);
                    $changes[] = "Added {$p['label']} (" . self::qty($qty) . " × {$p['price']})";
                    continue;
                }

                /** @var PurchaseOrderItem $line */
                $line     = $p['line'];
                $oldPrice = round((float) $line->unit_price, 2);
                $oldQtyH  = self::hundredths($line->quantity);
                $itemSwap = $line->item_type !== $p['type']
                    || (int) ($p['type'] === 'product' ? $line->product_id : $line->material_id) !== $p['itemId']
                    || (int) $line->product_variant_id !== (int) $p['variantId'];

                if ($itemSwap) {
                    $changes[] = "{$line->description} → {$p['label']}";
                }
                if ($oldQtyH !== $p['qtyH']) {
                    $changes[] = "{$p['label']}: quantity " . self::qty($oldQtyH / 100) . ' → ' . self::qty($qty);
                }
                if ($oldPrice !== $p['price']) {
                    $changes[] = "{$p['label']}: unit price {$oldPrice} → {$p['price']}";
                }

                $line->update([
                    'item_type'          => $p['type'],
                    'product_id'         => $p['type'] === 'product' ? $p['itemId'] : null,
                    'product_variant_id' => $p['type'] === 'product' ? $p['variantId'] : null,
                    'material_id'        => $p['type'] === 'material' ? $p['itemId'] : null,
                    'description'        => $p['label'],
                    'quantity'           => $qty,
                    'unit_price'         => $p['price'],
                    'total_price'        => $total,
                ]);

                // Goods on this line are in stock at the old price: correct the latest cost.
                if ($oldPrice !== $p['price'] && self::hundredths($line->quantity_received) > 0) {
                    $notes[] = $this->correctLatestCost($line->fresh(), $p['price'], (string) $po->currency_code);
                }
            }

            // ── Header ───────────────────────────────────────────────────────
            $header = [];
            $map = [
                'supplier_id'             => 'Supplier',
                'expected_delivery_date'  => 'Expected delivery',
                'payment_terms'           => 'Payment terms',
                'notes'                   => 'Notes',
                'supplier_invoice_number' => 'Supplier invoice number',
                'supplier_invoice_date'   => 'Supplier invoice date',
                'supplier_invoice_amount' => 'Supplier invoice amount',
            ];
            foreach ($map as $field => $label) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $old = $po->getRawOriginal($field);
                $new = $data[$field] === '' ? null : $data[$field];
                if ((string) $old !== (string) $new && !($field === 'supplier_invoice_amount' && $old !== null && $new !== null && round((float) $old, 2) === round((float) $new, 2))) {
                    $header[$field] = $new;
                    $changes[] = $field === 'supplier_id'
                        ? 'Supplier ' . ($po->supplier?->name ?? '—') . ' → ' . (\App\Models\Supplier::find($new)?->name ?? '—')
                        : "{$label}: " . ($old ?? '—') . ' → ' . ($new ?? '—');
                }
            }
            $shipping = array_key_exists('shipping_cost', $data) ? round((float) $data['shipping_cost'], 2) : round((float) $po->shipping_amount, 2);
            $tax      = array_key_exists('tax', $data) ? round((float) $data['tax'], 2) : round((float) $po->tax_amount, 2);
            if ($shipping !== round((float) $po->shipping_amount, 2)) {
                $changes[] = "Shipping {$po->shipping_amount} → {$shipping}";
            }
            if ($tax !== round((float) $po->tax_amount, 2)) {
                $changes[] = "Tax {$po->tax_amount} → {$tax}";
            }

            $subtotal = round($subtotal, 2);
            $total    = round($subtotal + $shipping + $tax, 2);
            if ($total !== round((float) $po->total_amount, 2)) {
                $changes[] = "Total {$po->total_amount} → {$total}";
            }

            if ($changes === []) {
                $this->refuse('items', 'Nothing was changed.');
            }

            // ── Status and approval ──────────────────────────────────────────
            $status = $this->receiptStatus($po->id, $po->status);
            $withdrawn = $po->status === 'pending_approval';
            if ($withdrawn) {
                $status = 'draft';
            }

            $revision = (int) $po->revision;
            DocumentRevision::create([
                'document_type' => DocumentRevision::PURCHASE_ORDER,
                'document_id'   => $po->id,
                'revision'      => $revision,
                'snapshot'      => $before,
                'changes'       => $changes,
                'reason'        => $data['reason'],
                'created_by'    => $owner->id,
            ]);

            $po->update(array_merge($header, [
                'subtotal'        => $subtotal,
                'shipping_amount' => $shipping,
                'tax_amount'      => $tax,
                'total_amount'    => $total,
                'status'          => $status,
                'revision'        => $revision + 1,
            ], $withdrawn ? ['approved_by' => null, 'approved_at' => null] : []));

            if ($withdrawn) {
                app(\App\Services\Approvals\ApprovalEngine::class)->cancelOpen(
                    'purchase_order', $po, $owner, 'The purchase order was edited while waiting for approval.',
                );
            }
            $approvalNote = in_array($before['status'], ['approved', 'ordered', 'partially_received', 'received'], true)
                ? 'Edited and re-approved by the owner.'
                : ($withdrawn ? 'Was waiting for approval: back to draft to be submitted again.' : null);

            ActivityLogService::log('po_revised', $po, [
                'revision'      => $revision + 1,
                'reason'        => $data['reason'],
                'changes'       => $changes,
                'old_status'    => $before['status'],
                'new_status'    => $status,
                'approval'      => $approvalNote,
                'cost_notes'    => array_values(array_filter($notes)),
            ], "PO {$po->po_number} revised to Rev " . ($revision + 1) . " by the owner: {$data['reason']}", $owner);

            return [
                'purchase_order' => $po->fresh(['items', 'supplier']),
                'changes'        => $changes,
                'notes'          => array_values(array_filter(array_merge($notes, [$approvalNote]))),
            ];
        });
    }

    // ── Goods receipt ────────────────────────────────────────────────────────

    /**
     * @param array $lines [{grn_item_id, quantity_received}]
     * @return array{grn: GoodsReceivedNote, changes: string[]}
     */
    public function correctGoodsReceipt(GoodsReceivedNote $grn, array $lines, string $reason, User $owner): array
    {
        return DB::transaction(function () use ($grn, $lines, $reason, $owner) {
            /** @var GoodsReceivedNote $grn */
            $grn = GoodsReceivedNote::whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $po  = PurchaseOrder::whereKey($grn->purchase_order_id)->lockForUpdate()->firstOrFail();
            $grn->load('items');

            $before  = $this->grnSnapshot($grn);
            $changes = [];

            foreach ($lines as $i => $in) {
                /** @var GrnItem|null $item */
                $item = $grn->items->firstWhere('id', (int) $in['grn_item_id']);
                if (!$item) {
                    $this->refuse("lines.$i.grn_item_id", 'That line is not on this goods receipt.');
                }
                $poItem = PurchaseOrderItem::whereKey($item->po_item_id)->lockForUpdate()->firstOrFail();

                $oldH  = self::hundredths($item->quantity_received);
                $newH  = self::hundredths($in['quantity_received']);
                $delta = $newH - $oldH;
                if ($delta === 0) {
                    continue;
                }
                if ($poItem->item_type === 'product' && $newH % 100 !== 0) {
                    $this->refuse("lines.$i.quantity_received", "{$poItem->description}: products are received in whole pieces.");
                }
                $poReceivedH = self::hundredths($poItem->quantity_received) + $delta;
                if ($poReceivedH > self::hundredths($poItem->quantity)) {
                    $this->refuse("lines.$i.quantity_received", "{$poItem->description}: that is more than the " . self::qty($poItem->quantity) . ' ordered. Raise the order line first.');
                }
                if ($poReceivedH < 0) {
                    $this->refuse("lines.$i.quantity_received", "{$poItem->description}: less than nothing would be received on the order.");
                }

                // Rejected goods never went into stock: only the count changes.
                $rejected = $item->condition === 'rejected';
                if (!$rejected) {
                    $poItem->item_type === 'product'
                        ? $this->moveProductStock($grn, $poItem, $delta / 100, $owner)
                        : $this->moveMaterialStock($grn, $poItem, $delta / 100, $owner);
                }

                $item->update(array_merge(
                    ['quantity_received' => $newH / 100],
                    $rejected ? ['quantity_rejected' => $newH / 100] : [],
                ));
                $poItem->update(['quantity_received' => $poReceivedH / 100]);

                $changes[] = "{$poItem->description}: received " . self::qty($oldH / 100) . ' → ' . self::qty($newH / 100)
                    . ($rejected ? ' (rejected goods — no stock moved)' : '');
            }

            if ($changes === []) {
                $this->refuse('lines', 'Nothing was changed.');
            }

            $revision = (int) $grn->revision;
            DocumentRevision::create([
                'document_type' => DocumentRevision::GOODS_RECEIPT,
                'document_id'   => $grn->id,
                'revision'      => $revision,
                'snapshot'      => $before,
                'changes'       => $changes,
                'reason'        => $reason,
                'created_by'    => $owner->id,
            ]);
            $grn->update(['revision' => $revision + 1]);

            $oldStatus = $po->status;
            $po->update(['status' => $this->receiptStatus($po->id, $po->status)]);

            ActivityLogService::log('grn_corrected', $grn, [
                'revision' => $revision + 1, 'reason' => $reason, 'changes' => $changes,
            ], "GRN {$grn->grn_number} corrected to Rev " . ($revision + 1) . " by the owner: {$reason}", $owner);
            ActivityLogService::log('grn_corrected', $po, [
                'grn_number' => $grn->grn_number, 'revision' => $revision + 1, 'reason' => $reason, 'changes' => $changes,
                'old_status' => $oldStatus, 'new_status' => $po->status,
            ], "GRN {$grn->grn_number} on PO {$po->po_number} corrected by the owner: {$reason}", $owner);

            return ['grn' => $grn->fresh('items'), 'changes' => $changes];
        });
    }

    /** Stock moves by the difference, at the place the receipt put it, with its serials. */
    private function moveProductStock(GoodsReceivedNote $grn, PurchaseOrderItem $poItem, float $delta, User $owner): void
    {
        $qty    = (int) round($delta);
        $outlet = $grn->outlet_id;
        $ref    = 'grn:' . $grn->grn_number . ':item:' . $poItem->id;

        $find = function (bool $create) use ($poItem, $outlet) {
            $keys = $poItem->product_variant_id
                ? ['product_variant_id' => $poItem->product_variant_id, 'outlet_id' => $outlet]
                : ['product_id' => $poItem->product_id, 'product_variant_id' => null, 'outlet_id' => $outlet];
            $q = InventoryItem::where($keys)->lockForUpdate();

            return $create
                ? ($q->first() ?? InventoryItem::create($keys + ['product_id' => $poItem->product_id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]))
                : $q->first();
        };

        if ($qty < 0) {
            $need = -$qty;
            $row  = $find(false);
            $free = $row ? (int) $row->quantity_on_hand - (int) $row->quantity_reserved : 0;
            if (!$row || $free < $need) {
                $this->refuse('lines', "{$poItem->description}: only " . max(0, $free) . ' of these are still on the shelf (the rest are sold or held for a sale). Take the rest back with a purchase return or a sales return.');
            }
            $serials = ProductSerial::where('source_reference', $ref)->where('status', ProductSerial::IN_STOCK)
                ->orderByDesc('id')->limit($need)->lockForUpdate()->get();
            $minted = ProductSerial::where('source_reference', $ref)->exists();
            if ($minted && $serials->count() < $need) {
                $this->refuse('lines', "{$poItem->description}: only {$serials->count()} of the units from this receipt are still in stock. Take the rest back with a purchase return.");
            }
            ProductSerial::whereIn('id', $serials->pluck('id'))->update(['status' => ProductSerial::CANCELLED, 'updated_at' => now()]);
            $row->adjustQuantity(-$need, 'purchase_correction', GoodsReceivedNote::class, $grn->id, $owner->id);
        } else {
            $row = $find(true);
            $row->adjustQuantity($qty, 'purchase_correction', GoodsReceivedNote::class, $grn->id, $owner->id);
            ProductSerialService::receiveIntoStock(
                $poItem->product_id, $poItem->product_variant_id, $outlet, $row->id,
                ProductSerial::where('source_reference', $ref)->count() + $qty, $ref, $owner->id,
            );
        }

        // The legacy table, exactly as receiving keeps it.
        Inventory::where('inventory_type', 'product')
            ->where('product_id', $poItem->product_id)
            ->when($poItem->product_variant_id, fn ($q) => $q->where('product_variant_id', $poItem->product_variant_id))
            ->when($outlet, fn ($q) => $q->where('outlet_id', $outlet), fn ($q) => $q->whereNull('outlet_id'))
            ->increment('quantity_on_hand', $qty);
    }

    private function moveMaterialStock(GoodsReceivedNote $grn, PurchaseOrderItem $poItem, float $delta, User $owner): void
    {
        $row = MaterialInventory::where(['material_id' => $poItem->material_id, 'outlet_id' => $grn->outlet_id])->lockForUpdate()->first();
        if ($delta < 0 && (!$row || self::hundredths($row->quantity_on_hand) < self::hundredths(-$delta))) {
            $this->refuse('lines', "{$poItem->description}: only " . self::qty($row?->quantity_on_hand ?? 0) . ' is still in stock. Take the rest back with a purchase return.');
        }
        $row ??= MaterialInventory::create(['material_id' => $poItem->material_id, 'outlet_id' => $grn->outlet_id, 'quantity_on_hand' => 0]);
        $row->adjustQuantity($delta, 'purchase_correction', 'goods_receipt', $grn->id, $owner->id);
    }

    /**
     * Receiving sets an item's latest cost to the price paid. Correct it when
     * this line is still the item's latest receipt; otherwise a later receipt
     * set the current cost and stays.
     */
    private function correctLatestCost(PurchaseOrderItem $line, float $price, string $currency): ?string
    {
        $latest = DB::table('grn_items as gi')
            ->join('goods_received_notes as g', 'g.id', '=', 'gi.grn_id')
            ->join('purchase_order_items as pi', 'pi.id', '=', 'gi.po_item_id')
            ->when($line->item_type === 'product',
                fn ($q) => $q->where('pi.product_id', $line->product_id)
                    ->when($line->product_variant_id, fn ($qq) => $qq->where('pi.product_variant_id', $line->product_variant_id),
                        fn ($qq) => $qq->whereNull('pi.product_variant_id')),
                fn ($q) => $q->where('pi.material_id', $line->material_id))
            ->orderByDesc('g.received_date')->orderByDesc('gi.id')
            ->value('gi.po_item_id');

        if ((int) $latest !== (int) $line->id) {
            return "{$line->description}: a later receipt set the current cost, so it stays.";
        }

        $kes = strtoupper($currency) === 'KES' ? $price : ReportingCurrency::toKes($price, $currency);
        if ($kes === null) {
            return "{$line->description}: no reporting rate for {$currency}, so the stock cost was not changed.";
        }
        $kes = round($kes, 2);

        if ($line->item_type === 'product') {
            Inventory::where('inventory_type', 'product')
                ->where('product_id', $line->product_id)
                ->when($line->product_variant_id, fn ($q) => $q->where('product_variant_id', $line->product_variant_id))
                ->update(['cost_per_unit' => $kes, 'updated_at' => now()]);
        } else {
            Material::whereKey($line->material_id)->update(['unit_cost' => $kes]);
        }

        return "{$line->description}: stock cost corrected to KES " . number_format($kes, 2) . '. Goods already sold keep the cost they were sold at.';
    }

    /** received / partially_received from what has come in; otherwise as it was (ordered when nothing has). */
    private function receiptStatus(int $poId, string $current): string
    {
        $items = PurchaseOrderItem::where('purchase_order_id', $poId)->get(['quantity', 'quantity_received']);
        $any   = $items->contains(fn ($i) => self::hundredths($i->quantity_received) > 0);
        $all   = $items->isNotEmpty() && $items->every(fn ($i) => self::hundredths($i->quantity_received) >= self::hundredths($i->quantity));

        if ($any) {
            return $all ? 'received' : 'partially_received';
        }

        return in_array($current, ['partially_received', 'received'], true) ? 'ordered' : $current;
    }

    private function labelFor(string $type, int $itemId, ?int $variantId, string $field): string
    {
        if ($type === 'material') {
            $m = Material::find($itemId) ?? $this->refuse("$field.item_id", "Material #{$itemId} not found.");

            return $m->name;
        }
        $product = Product::find($itemId) ?? $this->refuse("$field.item_id", "Product #{$itemId} not found.");
        $label   = $product->sku ?? "Product #{$itemId}";
        if ($variantId !== null) {
            $variant = ProductVariant::whereKey($variantId)->where('product_id', $product->id)->first()
                ?? $this->refuse("$field.product_variant_id", "Variant #{$variantId} is not a variant of {$label}.");
            $sku   = $variant->sku ?: $label;
            $label = mb_substr($variant->variant_name ? "{$sku} — {$variant->variant_name}" : $sku, 0, 255);
        }

        return $label;
    }

    private function poSnapshot(PurchaseOrder $po): array
    {
        return array_merge(
            $po->only(['po_number', 'revision', 'status', 'supplier_id', 'currency_code', 'order_date', 'expected_delivery_date',
                'payment_terms', 'notes', 'subtotal', 'tax_amount', 'shipping_amount', 'total_amount',
                'supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_amount', 'approved_by', 'approved_at']),
            [
                'supplier_name' => $po->supplier?->name,
                'items'         => $po->items->map(fn ($i) => $i->only(['id', 'item_type', 'product_id', 'product_variant_id', 'material_id',
                    'description', 'quantity', 'quantity_received', 'unit_price', 'total_price']))->values()->all(),
            ],
        );
    }

    private function grnSnapshot(GoodsReceivedNote $grn): array
    {
        $descriptions = PurchaseOrderItem::whereIn('id', $grn->items->pluck('po_item_id'))->pluck('description', 'id');

        return array_merge($grn->only(['grn_number', 'revision', 'purchase_order_id', 'outlet_id', 'received_date', 'invoice_number', 'notes']), [
            'items' => $grn->items->map(fn ($i) => $i->only(['id', 'po_item_id', 'quantity_received', 'quantity_rejected', 'condition'])
                + ['description' => $descriptions[$i->po_item_id] ?? null])->values()->all(),
        ]);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private static function hundredths($qty): int
    {
        return (int) round(((float) $qty) * 100);
    }

    private static function qty($q): string
    {
        $f = (float) $q;

        return fmod($f, 1.0) === 0.0 ? (string) (int) $f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    }
}
