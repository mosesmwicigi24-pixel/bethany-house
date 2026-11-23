<?php

namespace App\Services\Production;

use App\Models\BillOfMaterial;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ReportingCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where a production job's materials come from (owner, 2026-11-18/19).
 *
 * For each material on the product's bill of materials the person raising the
 * job says FROM STORE or NEEDS BUYING (or a split):
 *
 *   from store    the quantity is RESERVED for the job
 *                 (material_inventory.quantity_reserved — written for the
 *                 first time here), so a second job cannot claim the same
 *                 fabric. Issuing it to the floor releases the reservation.
 *   needs buying  the quantity goes onto the supplier's open DRAFT purchase
 *                 order, one line per job; later jobs join the same draft
 *                 until procurement submits it. Approval, sending and paying
 *                 stay with the existing procurement controls. When the
 *                 delivery is received the quantity becomes reserved for the
 *                 job.
 *
 * WHO the supplier is never leaves this class toward the caller: sales staff
 * choose "needs buying" and nothing more (owner, 2026-11-19). The supplier is
 * the material's preferred supplier, else whoever it was last bought from;
 * with neither, the need waits (quantity_to_buy with no purchase line) until
 * procurement sets one — placePending().
 *
 * Quantities carry two decimals, as material_allocations and
 * purchase_order_items store them.
 */
final class MaterialSourcing
{
    private const OPEN_PO = 'draft';

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * What making $quantity of a product needs, and how much of each material
     * is free in the store. No supplier, no price.
     *
     * @return array{has_bom: bool, materials: list<array<string,mixed>>}
     */
    public static function requirements(int $productId, float $quantity): array
    {
        $bom = self::bom($productId);
        if ($bom === null) {
            return ['has_bom' => false, 'materials' => []];
        }

        $rows = [];
        foreach ($bom->items as $item) {
            if (! $item->material) {
                continue;
            }
            $rows[] = [
                'material_id' => (int) $item->material_id,
                'name'        => $item->material->name,
                'unit'        => $item->unit_of_measure ?: $item->material->unit_of_measure,
                'required'    => self::q((float) $item->quantity * $quantity),
                'free'        => self::free((int) $item->material_id),
            ];
        }

        return ['has_bom' => true, 'materials' => $rows];
    }

    /**
     * A job's materials and where each stands. No supplier, no price.
     *
     * status: none (no materials listed) · unsourced (a choice is still to be
     * made) · waiting (something is being bought) · ready.
     *
     * @return array{status: string, materials: list<array<string,mixed>>}
     */
    public static function forOrder(ProductionOrder $order): array
    {
        $rows = [];
        $unsourced = false;
        $waiting = false;
        foreach (MaterialAllocation::with('material:id,name,unit_of_measure')->where('production_order_id', $order->id)->orderBy('id')->get() as $a) {
            $required = (float) $a->quantity_required;
            $issued   = (float) $a->quantity_allocated;
            $reserved = (float) $a->quantity_reserved;
            $toBuy    = (float) $a->quantity_to_buy;
            $open     = self::q(max(0.0, $required - $issued - $reserved - $toBuy));
            $unsourced = $unsourced || $open > 0;
            $waiting   = $waiting || $toBuy > 0;
            $rows[] = [
                'allocation_id' => (int) $a->id,
                'material_id'   => (int) $a->material_id,
                'name'          => $a->material?->name,
                'unit'          => $a->material?->unit_of_measure,
                'required'      => self::q($required),
                'issued'        => self::q($issued),
                'reserved'      => self::q($reserved),
                'to_buy'        => self::q($toBuy),
                'unsourced'     => $open,
                'free'          => self::free((int) $a->material_id),
            ];
        }

        $status = $rows === [] ? 'none' : ($unsourced ? 'unsourced' : ($waiting ? 'waiting' : 'ready'));

        return ['status' => $status, 'materials' => $rows];
    }

    // ── Deciding ─────────────────────────────────────────────────────────────

    /**
     * Record the choice for each material of a job. Call inside a transaction.
     *
     * $choices: [['material_id' => 1, 'from_store' => 2, 'to_buy' => 3], …].
     * For each material, from_store + to_buy must equal what is still
     * outstanding (required less already issued). Safe to call again: the
     * reservation and the draft purchase line move to the new figures.
     *
     * @param  list<array{material_id:int|string, from_store?:float|int|string|null, to_buy?:float|int|string|null}>  $choices
     */
    public static function source(ProductionOrder $order, array $choices, User $by): void
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['materials' => 'This job is closed; its materials can no longer be changed.']);
        }

        self::ensureAllocations($order);
        $allocations = MaterialAllocation::where('production_order_id', $order->id)->lockForUpdate()->get()->keyBy('material_id');

        foreach (array_values($choices) as $i => $choice) {
            $materialId = (int) ($choice['material_id'] ?? 0);
            $allocation = $allocations->get($materialId);
            if (! $allocation) {
                throw ValidationException::withMessages(["materials.{$i}.material_id" => 'This material is not on the job\'s bill of materials.']);
            }
            $material = Material::findOrFail($materialId);
            $store = self::q((float) ($choice['from_store'] ?? 0));
            $buy   = self::q((float) ($choice['to_buy'] ?? 0));
            if ($store < 0 || $buy < 0) {
                throw ValidationException::withMessages(["materials.{$i}" => 'Quantities cannot be negative.']);
            }

            $outstanding = self::q((float) $allocation->quantity_required - (float) $allocation->quantity_allocated);
            if (self::cents($store + $buy) !== self::cents($outstanding)) {
                throw ValidationException::withMessages(["materials.{$i}" => "{$material->name}: from store and to buy must add up to " . self::show($outstanding) . " {$material->unit_of_measure}."]);
            }

            // From store: move the reservation to the new figure.
            $delta = self::q($store - (float) $allocation->quantity_reserved);
            if ($delta > 0) {
                $free = self::free($materialId);
                if (self::cents($delta) > self::cents($free)) {
                    throw ValidationException::withMessages(["materials.{$i}.from_store" => "{$material->name}: only " . self::show($free) . " {$material->unit_of_measure} free in the store."]);
                }
                self::reserve($materialId, $delta, $order->outlet_id);
            } elseif ($delta < 0) {
                self::unreserve($materialId, -$delta);
            }

            // To buy: what is already with a supplier cannot be taken back here.
            $sent = self::q((float) self::lines($order->id, $materialId, open: false)->sum(DB::raw('GREATEST(quantity - quantity_received, 0)')));
            if (self::cents($buy) < self::cents($sent)) {
                throw ValidationException::withMessages(["materials.{$i}.to_buy" => "{$material->name}: " . self::show($sent) . " {$material->unit_of_measure} is already on an order to the supplier. Ask procurement to change it."]);
            }

            $allocation->update([
                'quantity_reserved' => $store,
                'quantity_to_buy'   => $buy,
                'sourced_at'        => now(),
                'sourced_by'        => $by->id,
            ]);
            self::setDraftLine($order, $material, self::q($buy - $sent), $by);
        }

        try {
            ActivityLogService::log('production_materials_sourced', $order, [
                'order_number' => $order->order_number,
                'materials'    => array_map(fn ($c) => [
                    'material_id' => (int) ($c['material_id'] ?? 0),
                    'from_store'  => self::q((float) ($c['from_store'] ?? 0)),
                    'to_buy'      => self::q((float) ($c['to_buy'] ?? 0)),
                ], array_values($choices)),
            ]);
        } catch (\Throwable) {
        }
    }

    /** One allocation row per material on the product's bill of materials. */
    public static function ensureAllocations(ProductionOrder $order): void
    {
        $bom = self::bom((int) $order->product_id);
        if ($bom === null) {
            return;
        }
        foreach ($bom->items as $item) {
            MaterialAllocation::firstOrCreate(
                ['production_order_id' => $order->id, 'material_id' => $item->material_id],
                ['quantity_required' => self::q((float) $item->quantity * (float) $order->quantity), 'quantity_allocated' => 0, 'quantity_used' => 0, 'quantity_returned' => 0],
            );
        }
    }

    // ── Life after the choice ────────────────────────────────────────────────

    /**
     * A job closed. Its store reservations are released. On a CANCEL its
     * lines come off any purchase order still in draft; a line already sent
     * to a supplier stays (the material is coming) and is reported back.
     *
     * @return list<string>  purchase order numbers still carrying a line for this job
     */
    public static function release(ProductionOrder $order, bool $cancelled): array
    {
        return DB::transaction(function () use ($order, $cancelled) {
            $stillOnOrder = [];
            foreach (MaterialAllocation::where('production_order_id', $order->id)->lockForUpdate()->get() as $a) {
                if ((float) $a->quantity_reserved > 0) {
                    self::unreserve((int) $a->material_id, (float) $a->quantity_reserved);
                }
                $a->update(['quantity_reserved' => 0, 'quantity_to_buy' => 0]);
            }

            if ($cancelled) {
                $touched = [];
                foreach (self::lines($order->id, null, open: true)->get() as $line) {
                    $touched[$line->purchase_order_id] = true;
                    $line->delete();
                }
                foreach (array_keys($touched) as $poId) {
                    self::retotal((int) $poId);
                }
                $stillOnOrder = self::lines($order->id, null, open: false)
                    ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
                    ->whereRaw('purchase_order_items.quantity > purchase_order_items.quantity_received')
                    ->distinct()->pluck('po.po_number')->all();
                if ($stillOnOrder !== []) {
                    try {
                        ActivityLogService::log('production_materials_still_on_order', $order, [
                            'order_number'    => $order->order_number,
                            'purchase_orders' => $stillOnOrder,
                        ], "Job {$order->order_number} was cancelled with materials already on order: " . implode(', ', $stillOnOrder));
                    } catch (\Throwable) {
                    }
                }
            }

            return $stillOnOrder;
        });
    }

    /** Material was issued to the floor: that much of the job's reservation is spent. */
    public static function onIssued(MaterialAllocation $allocation, float $quantity): void
    {
        $spent = self::q(min($quantity, (float) $allocation->quantity_reserved));
        if ($spent <= 0) {
            return;
        }
        self::unreserve((int) $allocation->material_id, $spent);
        $allocation->update(['quantity_reserved' => self::q((float) $allocation->quantity_reserved - $spent)]);
    }

    /** A delivery was received for a line raised for a job: it is now held for that job. */
    public static function onReceived(PurchaseOrderItem $line, float $quantity, ?int $outletId): void
    {
        if (! $line->production_order_id || ! $line->material_id) {
            return;
        }
        $job = ProductionOrder::find($line->production_order_id);
        if (! $job || in_array($job->status, ['completed', 'cancelled'], true)) {
            return;                                   // free stock: nobody is waiting for it
        }
        $allocation = MaterialAllocation::where('production_order_id', $job->id)->where('material_id', $line->material_id)->lockForUpdate()->first();
        if (! $allocation) {
            return;
        }
        $move = self::q(min($quantity, (float) $allocation->quantity_to_buy));
        if ($move <= 0) {
            return;
        }
        // Never hold more than is actually free (a short or disputed delivery).
        $move = self::q(min($move, self::free((int) $line->material_id)));
        if ($move <= 0) {
            return;
        }
        self::reserve((int) $line->material_id, $move, $outletId);
        $allocation->update([
            'quantity_to_buy'   => self::q((float) $allocation->quantity_to_buy - $move),
            'quantity_reserved' => self::q((float) $allocation->quantity_reserved + $move),
        ]);
    }

    /**
     * A material was given a supplier: needs that were waiting for one go
     * onto that supplier's draft. Returns how many lines were placed.
     */
    public static function placePending(Material $material, User $by): int
    {
        return DB::transaction(function () use ($material, $by) {
            $placed = 0;
            $waiting = MaterialAllocation::where('material_id', $material->id)->where('quantity_to_buy', '>', 0)
                ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))->get();
            foreach ($waiting as $a) {
                $onLines = (float) self::lines((int) $a->production_order_id, (int) $material->id, open: null)->sum(DB::raw('GREATEST(quantity - quantity_received, 0)'));
                $missing = self::q((float) $a->quantity_to_buy - $onLines);
                if ($missing > 0 && $a->productionOrder) {
                    self::setDraftLine($a->productionOrder, $material, $missing, $by);
                    $placed++;
                }
            }

            return $placed;
        });
    }

    /** How much of a material is asked for by jobs but on no purchase order (no supplier yet). */
    public static function unplaced(int $materialId): float
    {
        $toBuy = (float) MaterialAllocation::where('material_id', $materialId)->where('quantity_to_buy', '>', 0)
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))->sum('quantity_to_buy');
        $onLines = (float) PurchaseOrderItem::where('material_id', $materialId)->whereNotNull('production_order_id')
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->sum(DB::raw('GREATEST(quantity - quantity_received, 0)'));

        return self::q(max(0.0, $toBuy - $onLines));
    }

    /**
     * Procurement moves a job's line to another supplier while its purchase
     * order is still a draft (owner, 2026-11-19). The line joins that
     * supplier's open draft; an emptied draft is removed.
     */
    public static function moveLine(PurchaseOrderItem $line, int $supplierId, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($line, $supplierId, $by) {
            $from = PurchaseOrder::whereKey($line->purchase_order_id)->lockForUpdate()->firstOrFail();
            if ($from->status !== self::OPEN_PO) {
                throw ValidationException::withMessages(['supplier_id' => 'Only a line on a draft purchase order can move to another supplier. Cancel this order and raise a new one instead.']);
            }
            if ((int) $from->supplier_id === $supplierId) {
                return $from;
            }
            $job = $line->production_order_id ? ProductionOrder::find($line->production_order_id) : null;
            $to  = self::openDraft($supplierId, $job?->outlet_id ?? $from->outlet_id, $by);

            $existing = PurchaseOrderItem::where('purchase_order_id', $to->id)->where('material_id', $line->material_id)
                ->where('production_order_id', $line->production_order_id)->first();
            if ($existing && $line->production_order_id) {
                $existing->update(['quantity' => self::q((float) $existing->quantity + (float) $line->quantity), 'total_price' => round(((float) $existing->quantity + (float) $line->quantity) * (float) $existing->unit_price, 2)]);
                $line->delete();
            } else {
                $line->update(['purchase_order_id' => $to->id]);
            }
            self::retotal((int) $from->id);
            self::retotal((int) $to->id);

            try {
                ActivityLogService::log('purchase_line_moved', $to, [
                    'from_po' => $from->po_number, 'to_po' => $to->po_number, 'material_id' => $line->material_id,
                    'quantity' => (float) $line->quantity, 'production_order_id' => $line->production_order_id,
                ], "Moved a material line from {$from->po_number} to {$to->po_number}");
            } catch (\Throwable) {
            }

            return $to;
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function bom(int $productId): ?BillOfMaterial
    {
        // By product, as ProductionController::confirm reads it (the variant
        // is not part of the lookup there either).
        return BillOfMaterial::with('items.material')->where('product_id', $productId)->where('is_active', true)->first();
    }

    /** Free in the store: on hand less reserved, across the material's rows. Never negative. */
    public static function free(int $materialId): float
    {
        return self::q(max(0.0, (float) DB::table('material_inventory')->where('material_id', $materialId)->sum('quantity_available')));
    }

    private static function reserve(int $materialId, float $quantity, ?int $preferOutletId): void
    {
        $left = self::q($quantity);
        if ($left <= 0) {
            return;
        }
        $rows = DB::table('material_inventory')->where('material_id', $materialId)->lockForUpdate()->orderBy('id')->get()
            ->sortByDesc(fn ($r) => (int) ($preferOutletId !== null && (int) $r->outlet_id === $preferOutletId))->values();
        foreach ($rows as $row) {
            $take = self::q(min($left, max(0.0, (float) $row->quantity_on_hand - (float) $row->quantity_reserved)));
            if ($take > 0) {
                DB::table('material_inventory')->where('id', $row->id)->update(['quantity_reserved' => DB::raw('quantity_reserved + ' . number_format($take, 2, '.', '')), 'updated_at' => now()]);
                $left = self::q($left - $take);
            }
            if ($left <= 0) {
                return;
            }
        }
        throw ValidationException::withMessages(['materials' => 'Not enough of this material free in the store to reserve.']);
    }

    private static function unreserve(int $materialId, float $quantity): void
    {
        $left = self::q($quantity);
        foreach (DB::table('material_inventory')->where('material_id', $materialId)->where('quantity_reserved', '>', 0)->lockForUpdate()->orderBy('id')->get() as $row) {
            if ($left <= 0) {
                break;
            }
            $give = self::q(min($left, (float) $row->quantity_reserved));
            DB::table('material_inventory')->where('id', $row->id)->update(['quantity_reserved' => DB::raw('quantity_reserved - ' . number_format($give, 2, '.', '')), 'updated_at' => now()]);
            $left = self::q($left - $give);
        }
    }

    /** A job's purchase lines: on drafts (open true), on sent orders (false), or all live (null). */
    private static function lines(int $productionOrderId, ?int $materialId, ?bool $open)
    {
        return PurchaseOrderItem::query()
            ->where('purchase_order_items.production_order_id', $productionOrderId)
            ->when($materialId !== null, fn ($q) => $q->where('purchase_order_items.material_id', $materialId))
            ->whereHas('purchaseOrder', fn ($q) => match ($open) {
                true    => $q->where('status', self::OPEN_PO),
                false   => $q->whereNotIn('status', [self::OPEN_PO, 'cancelled']),
                default => $q->where('status', '<>', 'cancelled'),
            });
    }

    /** Put the job's draft purchase line for a material at $quantity (0 removes it). */
    private static function setDraftLine(ProductionOrder $order, Material $material, float $quantity, User $by): void
    {
        $line = self::lines((int) $order->id, (int) $material->id, open: true)->first();

        if ($quantity <= 0) {
            if ($line) {
                $poId = (int) $line->purchase_order_id;
                $line->delete();
                self::retotal($poId);
            }

            return;
        }

        if ($line) {
            // Stays on the draft procurement has it on (they may have moved it).
            $line->update(['quantity' => $quantity, 'total_price' => round($quantity * (float) $line->unit_price, 2)]);
            self::retotal((int) $line->purchase_order_id);

            return;
        }

        $supplierId = self::supplierFor($material);
        if ($supplierId === null) {
            return;                                   // waits for procurement: placePending()
        }
        $po    = self::openDraft($supplierId, $order->outlet_id, $by);
        $price = self::priceKes($material);
        PurchaseOrderItem::create([
            'purchase_order_id'   => $po->id,
            'item_type'           => 'material',
            'product_id'          => null,
            'material_id'         => $material->id,
            'description'         => $material->name,
            'quantity'            => $quantity,
            'quantity_received'   => 0,
            'unit_price'          => $price,
            'tax_amount'          => 0,
            'total_price'         => round($quantity * $price, 2),
            'production_order_id' => $order->id,
        ]);
        self::retotal((int) $po->id);
    }

    /** The material's supplier: the one procurement set, else whoever it was last bought from. */
    private static function supplierFor(Material $material): ?int
    {
        if ($material->preferred_supplier_id) {
            return (int) $material->preferred_supplier_id;
        }
        $last = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.material_id', $material->id)->where('po.status', '<>', 'cancelled')
            ->orderByDesc('po.order_date')->orderByDesc('po.id')->value('po.supplier_id');

        return $last ? (int) $last : null;
    }

    /** The supplier's open draft that production needs collect on; started when there is none. */
    private static function openDraft(int $supplierId, ?int $outletId, User $by): PurchaseOrder
    {
        $po = PurchaseOrder::where('supplier_id', $supplierId)->where('status', self::OPEN_PO)
            ->where('collects_production_needs', true)->where('currency_code', 'KES')
            ->orderBy('id')->lockForUpdate()->first();
        if ($po) {
            return $po;
        }

        $po = PurchaseOrder::create([
            'po_number'                 => PurchaseOrder::nextNumber(),
            'supplier_id'               => $supplierId,
            'outlet_id'                 => $outletId,
            'order_date'                => now()->toDateString(),
            'expected_delivery_date'    => now()->addDays(7)->toDateString(),
            'status'                    => self::OPEN_PO,
            'currency_code'             => 'KES',
            'subtotal'                  => 0,
            'tax_amount'                => 0,
            'shipping_amount'           => 0,
            'total_amount'              => 0,
            'payment_status'            => 'unpaid',
            'notes'                     => 'Materials for production jobs. New needs for this supplier are added here until it is submitted.',
            'created_by'                => $by->id,
            'collects_production_needs' => true,
        ]);
        try {
            ActivityLogService::log('created', $po, ['po_number' => $po->po_number, 'source' => 'production_material_sourcing'],
                "Started draft PO {$po->po_number} for production material needs");
        } catch (\Throwable) {
        }

        return $po;
    }

    /** Last price paid for the material, in KES at the reporting rate; else its unit cost. */
    private static function priceKes(Material $material): float
    {
        $priceKes = ReportingCurrency::kes('poi.unit_price', 'po.currency_code');
        $canKes   = ReportingCurrency::convertibleFilter('po.currency_code');
        $last = DB::selectOne("
            SELECT {$priceKes} AS price
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.status <> 'cancelled'
            WHERE poi.material_id = ? AND poi.unit_price > 0 AND {$canKes}
              AND po.status <> 'draft'
            ORDER BY po.order_date DESC, po.id DESC
            LIMIT 1
        ", [$material->id]);

        return round((float) ($last->price ?? $material->unit_cost ?? 0), 2);
    }

    /** A purchase order's totals from its lines; an emptied collecting draft is removed. */
    private static function retotal(int $purchaseOrderId): void
    {
        $po = PurchaseOrder::find($purchaseOrderId);
        if (! $po) {
            return;
        }
        if ($po->collects_production_needs && $po->status === self::OPEN_PO && ! $po->items()->exists()) {
            $po->delete();

            return;
        }
        $subtotal = round((float) $po->items()->sum('total_price'), 2);
        $po->update([
            'subtotal'     => $subtotal,
            'total_amount' => round($subtotal + (float) $po->tax_amount + (float) $po->shipping_amount, 2),
        ]);
    }

    private static function q(float $n): float
    {
        return round($n, 2);
    }

    private static function cents(float $n): int
    {
        return (int) round($n * 100);
    }

    private static function show(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }
}
