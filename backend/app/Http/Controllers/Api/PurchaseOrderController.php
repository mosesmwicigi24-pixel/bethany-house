<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceivedNote;
use App\Models\GrnItem;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\MaterialTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Procurement\LatestCost;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\ProductVariant;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\ProductSerialService;
use App\Support\MakerChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\ServerError;

class PurchaseOrderController extends Controller
{
    // =========================================================================
    // GET /api/v1/admin/purchase-orders
    // =========================================================================

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier:id,name,company_code', 'items', 'createdBy:id,first_name,last_name']);

        // Multi-status filter (comma-separated e.g. "ordered,partially_received")
        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $query->where('po_number', 'ILIKE', "%{$request->search}%");
        }

        $sortBy    = in_array($request->get('sort_by'), ['order_date', 'total_amount', 'status', 'po_number'])
            ? $request->get('sort_by') : 'order_date';
        $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage       = min((int) $request->get('per_page', 20), 100);
        $purchaseOrders = $query->paginate($perPage);

        return response()->json($purchaseOrders);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-orders/statistics
    // =========================================================================

    public function statistics(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->get('end_date',   now()->endOfMonth()->toDateString());

        return response()->json([
            'total_orders'    => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->count(),
            'draft'           => PurchaseOrder::where('status', 'draft')->count(),
            'pending_approval'=> PurchaseOrder::where('status', 'pending_approval')->count(),
            'approved'        => PurchaseOrder::where('status', 'approved')->count(),
            'ordered'         => PurchaseOrder::where('status', 'ordered')->count(),
            'partially_received' => PurchaseOrder::where('status', 'partially_received')->count(),
            'received'        => PurchaseOrder::where('status', 'received')->count(),
            'cancelled'       => PurchaseOrder::where('status', 'cancelled')->count(),
            'total_value'     => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])
                                    ->whereNotIn('status', ['cancelled'])
                                    ->sum('total_amount'),
            'outstanding_value' => PurchaseOrder::whereIn('status', ['approved', 'ordered', 'partially_received'])
                                    ->sum('total_amount'),
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-orders/currencies
    // =========================================================================

    /** The currencies a purchase order may be raised in: those with a reporting rate. */
    public function currencies()
    {
        $rates = \App\Support\ReportingCurrency::rates();

        return response()->json(['data' => collect(['KES', 'USD', 'EUR', 'GBP'])->map(fn ($c) => [
            'code'        => $c,
            'rate_to_kes' => $rates[$c] ?? null,
            'allowed'     => isset($rates[$c]),
        ])->values()]);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-orders/item-costs/{productId}?currency=KES
    // =========================================================================

    /**
     * What the New Purchase Order form pre-fills when a product is picked: the
     * product's variants (for the "Add all variants" grid) and, for the
     * product and each variant, the KES cost from the price book and that cost
     * stated in the PO's currency.
     *
     * The cost is the canonical book cost (CostBasis::unitCostFor — the
     * variant's KES row, else the product's), so the PO starts from the same
     * figure COGS uses. A foreign PO converts at the REPORTING rate: that is
     * the rate the approval engine turns the PO back into KES with
     * (PurchaseOrderHandler::amount), so a PO pre-filled at cost bands at that
     * cost. A currency with no reporting rate gets no figure — never a guess.
     * No cost on the book, or a viewer without products.view_cost: null, and
     * the form asks for the price instead of using 0.
     */
    public function itemCosts(Request $request, $productId)
    {
        $validated = $request->validate([
            'currency' => 'nullable|in:KES,USD,EUR,GBP',
        ]);
        $currency = $validated['currency'] ?? 'KES';

        $product = Product::with(['translations' => fn ($q) => $q->where('language_code', 'en')])->findOrFail($productId);
        $variants = ProductVariant::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'sku', 'variant_name', 'attributes']);

        $seesCost = \App\Support\CostVisibility::allows($request->user());
        $rate     = \App\Support\ReportingCurrency::rates()[$currency] ?? null;
        $line = function (?int $variantId) use ($product, $seesCost, $rate): array {
            $kes = $seesCost ? \App\Support\CostBasis::unitCostFor($product->id, $variantId) : null;

            return [
                'kes_cost'   => $kes,
                'unit_price' => ($kes === null || $rate === null) ? null : round($kes / $rate, 2),
            ];
        };

        return response()->json([
            'product' => [
                'id'   => $product->id,
                'sku'  => $product->sku,
                'name' => $product->translations->first()?->name ?? $product->sku,
            ] + $line(null),
            'currency'     => $currency,
            'rate_to_kes'  => $rate,
            'rate_missing' => $rate === null,
            'cost_visible' => $seesCost,
            'variants'     => $variants->map(fn ($v) => [
                'id'           => $v->id,
                'sku'          => $v->sku,
                'variant_name' => $v->variant_name,
                'attributes'   => $v->attributes ?? (object) [],
            ] + $line($v->id))->values(),
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-orders/{id}
    // =========================================================================

    public function show($id)
    {
        $purchaseOrder = PurchaseOrder::with([
            'supplier',
            'items.product.translations' => fn ($q) => $q->where('language_code', 'en'),
            'items.variant:id,sku,variant_name',
            'items.material:id,name,unit_of_measure',
            'createdBy:id,first_name,last_name',
            'approvedBy:id,first_name,last_name',
            'goodsReceivedNotes.receivedBy:id,first_name,last_name',
        ])->findOrFail($id);

        return response()->json([
            'purchase_order'    => $purchaseOrder,
            'receiving_history' => $purchaseOrder->goodsReceivedNotes,
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'             => 'required|exists:suppliers,id',
            'expected_delivery_date'  => 'required|date|after:today',
            'currency'                => ['required', 'in:KES,USD,EUR,GBP', function ($attr, $value, $fail) {
                // Goods bought in a currency the books cannot state in KES
                // would have no cost, no approval band and no payable figure.
                if (!in_array(strtoupper((string) $value), \App\Support\ReportingCurrency::convertibleCodes(), true)) {
                    $fail("There is no reporting rate for {$value}. Ask finance to set one, or raise the purchase order in KES.");
                }
            }],
            'shipping_cost'           => 'nullable|numeric|min:0',
            'tax'                     => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string',
            'payment_terms'           => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.type'            => 'required|in:product,material',
            'items.*.item_id'         => 'required|integer',
            // A product line may name one of the product's variants; stock is
            // then received onto that variant (receiveProductInventory).
            'items.*.product_variant_id' => 'nullable|integer',
            // Materials are bought by the metre (0.25, 0.5, 0.75); the columns
            // are decimal(12,2), so 2 places is the precision that survives.
            'items.*.quantity'        => 'required|numeric|min:0.01|decimal:0,2',
            'items.*.unit_price'      => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $shippingAmount = $validated['shipping_cost'] ?? 0;
            $taxAmount      = $validated['tax'] ?? 0;
            $subtotal       = 0;

            // Pre-validate all items before creating anything.
            //
            // A variant line must be one of ITS product's variants, and a
            // variant appears on the order once: the same variant sent twice
            // at the same price is one line with the quantities added (the
            // "Add all variants" grid can be used again for the same product);
            // at two different prices it is refused — which price stands is
            // the buyer's call, not ours. Product lines without a variant and
            // material lines keep their old rule (repeats allowed).
            $itemDetails = [];
            $variantLine = [];   // variant id => index in $itemDetails
            foreach ($validated['items'] as $item) {
                $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
                $item['product_variant_id'] = $variantId;

                if ($item['type'] === 'product' && !self::isWhole($item['quantity'])) {
                    DB::rollBack();
                    return response()->json(['message' => 'Products are ordered in whole pieces — only materials take fractional quantities.'], 422);
                }
                if ($item['type'] === 'product') {
                    $product = Product::find($item['item_id']);
                    if (!$product) {
                        DB::rollBack();
                        return response()->json(['message' => "Product ID {$item['item_id']} not found."], 422);
                    }
                    $label = $product->sku ?? "Product #{$item['item_id']}";
                    if ($variantId !== null) {
                        $variant = ProductVariant::whereKey($variantId)->where('product_id', $product->id)->first();
                        if (!$variant) {
                            DB::rollBack();
                            return response()->json([
                                'message' => "Variant #{$variantId} is not a variant of {$label}.",
                                'errors'  => ['items' => ["Variant #{$variantId} is not a variant of {$label}."]],
                            ], 422);
                        }
                        $sku   = $variant->sku ?: $label;
                        $label = mb_substr($variant->variant_name ? "{$sku} — {$variant->variant_name}" : $sku, 0, 255);

                        if (isset($variantLine[$variantId])) {
                            $idx = $variantLine[$variantId];
                            if ((int) round((float) $itemDetails[$idx]['unit_price'] * 100) !== (int) round((float) $item['unit_price'] * 100)) {
                                DB::rollBack();
                                $msg = "{$label} is on the order twice at different unit prices. Keep one line per variant.";
                                return response()->json(['message' => $msg, 'errors' => ['items' => [$msg]]], 422);
                            }
                            $itemDetails[$idx]['quantity'] = (self::hundredths($itemDetails[$idx]['quantity']) + self::hundredths($item['quantity'])) / 100;
                            continue;
                        }
                        $variantLine[$variantId] = count($itemDetails);
                    }
                    $itemDetails[] = array_merge($item, ['label' => $label]);
                } else {
                    if ($variantId !== null) {
                        DB::rollBack();
                        return response()->json(['message' => 'A material line cannot name a product variant.'], 422);
                    }
                    $material = Material::find($item['item_id']);
                    if (!$material) {
                        DB::rollBack();
                        return response()->json(['message' => "Material ID {$item['item_id']} not found."], 422);
                    }
                    $itemDetails[] = array_merge($item, ['label' => $material->name]);
                }
            }
            foreach ($itemDetails as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $totalAmount = $subtotal + $shippingAmount + $taxAmount;

            // PO number generated by model boot, but we pass it explicitly for uniqueness safety
            $poNumber = $this->nextPoNumber();

            $purchaseOrder = PurchaseOrder::create([
                'po_number'              => $poNumber,
                'supplier_id'            => $validated['supplier_id'],
                'order_date'             => now()->toDateString(),
                'expected_delivery_date' => $validated['expected_delivery_date'],
                'status'                 => 'draft',
                'currency_code'          => $validated['currency'],
                'subtotal'               => $subtotal,
                'tax_amount'             => $taxAmount,
                'shipping_amount'        => $shippingAmount,
                'total_amount'           => $totalAmount,
                'payment_status'         => 'unpaid',
                'payment_terms'          => $validated['payment_terms'] ?? null,
                'notes'                  => $validated['notes'] ?? null,
                'created_by'             => $request->user()->id,
            ]);

            foreach ($itemDetails as $item) {
                $lineTotal = $item['quantity'] * $item['unit_price'];

                PurchaseOrderItem::create([
                    'purchase_order_id'  => $purchaseOrder->id,
                    'item_type'          => $item['type'],
                    'product_id'         => $item['type'] === 'product' ? $item['item_id'] : null,
                    'product_variant_id' => $item['type'] === 'product' ? $item['product_variant_id'] : null,
                    'material_id'        => $item['type'] === 'material' ? $item['item_id'] : null,
                    'description'        => $item['label'],
                    'quantity'           => $item['quantity'],
                    'quantity_received'  => 0,
                    'unit_price'         => $item['unit_price'],
                    'tax_amount'         => 0,
                    'total_price'        => $lineTotal,
                ]);
            }

            DB::commit();

            // ── Audit log ────────────────────────────────────────────────────
            $supplier = Supplier::find($validated['supplier_id']);
            ActivityLogService::log('created', $purchaseOrder, [
                'po_number'         => $purchaseOrder->po_number,
                'supplier_name'     => $supplier?->name,
                'items_count'       => count($itemDetails),
                'total_amount'      => $purchaseOrder->total_amount,
                'currency'          => $purchaseOrder->currency_code,
                'expected_delivery' => $purchaseOrder->expected_delivery_date,
            ], "Created PO {$purchaseOrder->po_number} to supplier " . ($supplier?->name ?? 'Unknown') . " for {$purchaseOrder->currency_code} " . number_format($purchaseOrder->total_amount, 2));

            // ── Notification ─────────────────────────────────────────────────
            NotificationService::purchaseOrderCreated(
                $purchaseOrder->id,
                $purchaseOrder->po_number,
                $supplier?->name ?? 'Unknown',
                (float) $purchaseOrder->total_amount,
                $purchaseOrder->currency_code
            );

            return response()->json([
                'message'        => 'Purchase order created successfully.',
                'purchase_order' => $purchaseOrder->load(['supplier:id,name', 'items']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to create purchase order.');
        }
    }

    /** Next unique PO number: today's sequence, falling back to a random suffix. */
    private function nextPoNumber(): string
    {
        $poNumber = 'PO-' . date('Ymd') . '-' . str_pad(
            PurchaseOrder::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT
        );
        while (PurchaseOrder::where('po_number', $poNumber)->exists()) {
            $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4));
        }

        return $poNumber;
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/from-suggestions
    // =========================================================================

    /**
     * One click from the attention feed: build draft PO(s) for the given
     * materials from the SAME three real numbers as the purchase-suggestion
     * report — available stock, reorder buffer, open production demand —
     * priced at the last price actually paid (else the material's unit cost).
     *
     * Materials are grouped by their most recent supplier — one draft per
     * supplier; materials never purchased before ride along with the most
     * recently used supplier across the selection. Everything stays a DRAFT:
     * the normal submit → approve flow still applies.
     */
    public function storeFromSuggestions(Request $request)
    {
        $validated = $request->validate([
            'material_ids'   => 'required|array|min:1',
            'material_ids.*' => 'integer|distinct|exists:materials,id',
        ]);

        $ids = array_map('intval', $validated['material_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // The drafts are in KES, so the last price paid is stated in KES at
        // the reporting rate; a PO in a currency with no rate is skipped.
        $priceKes = \App\Support\ReportingCurrency::kes('poi.unit_price', 'po2.currency_code');
        $canKes   = \App\Support\ReportingCurrency::convertibleFilter('po2.currency_code');

        $rows = DB::select("
            SELECT m.id, m.name, m.unit_of_measure, m.unit_cost, m.reorder_point,
                   COALESCE(a.available, 0) AS available,
                   COALESCE(d.shortfall, 0) AS open_demand,
                   GREATEST(m.reorder_point + COALESCE(d.shortfall, 0) - COALESCE(a.available, 0), 0) AS suggested,
                   COALESCE(b.used_30d, 0)  AS used_30d,
                   lb.unit_price   AS last_price,
                   lb.supplier_id  AS last_supplier_id,
                   lb.order_date   AS last_ordered
            FROM materials m
            LEFT JOIN (
                SELECT mi.material_id, SUM(mi.quantity_available) AS available
                FROM material_inventory mi GROUP BY mi.material_id
            ) a ON a.material_id = m.id
            LEFT JOIN (
                SELECT ma.material_id, SUM(GREATEST(ma.quantity_required - ma.quantity_allocated, 0)) AS shortfall
                FROM material_allocations ma
                JOIN production_orders p ON p.id = ma.production_order_id
                WHERE p.status NOT IN ('completed','cancelled','draft')
                GROUP BY ma.material_id
            ) d ON d.material_id = m.id
            LEFT JOIN (
                SELECT ma.material_id, SUM(ma.quantity_used) AS used_30d
                FROM material_allocations ma
                JOIN production_orders p2 ON p2.id = ma.production_order_id
                WHERE p2.status <> 'cancelled' AND p2.created_at >= ?
                GROUP BY ma.material_id
            ) b ON b.material_id = m.id
            LEFT JOIN (
                SELECT DISTINCT ON (poi.material_id)
                       poi.material_id, {$priceKes} AS unit_price, po2.supplier_id, po2.order_date
                FROM purchase_order_items poi
                JOIN purchase_orders po2 ON po2.id = poi.purchase_order_id AND po2.status <> 'cancelled'
                WHERE poi.material_id IS NOT NULL AND {$canKes}
                ORDER BY poi.material_id, po2.order_date DESC
            ) lb ON lb.material_id = m.id
            WHERE m.id IN ({$placeholders}) AND m.is_active = TRUE
        ", array_merge([now()->subDays(30)], $ids));

        if (empty($rows)) {
            return response()->json(['message' => 'None of the selected materials are active.'], 422);
        }

        // Fallback supplier: the most recently used across the whole selection.
        $fallbackSupplierId = collect($rows)
            ->filter(fn ($r) => $r->last_supplier_id !== null)
            ->sortByDesc(fn ($r) => (string) $r->last_ordered)
            ->first()?->last_supplier_id;

        if ($fallbackSupplierId === null) {
            return response()->json([
                'message' => 'No purchase history for the selected materials — no supplier to draft against. Create the purchase order manually.',
            ], 422);
        }

        // One draft per supplier: each material goes to its own last supplier,
        // history-less materials to the fallback.
        $groups = collect($rows)->groupBy(fn ($r) => (int) ($r->last_supplier_id ?? $fallbackSupplierId));

        $created = [];
        DB::beginTransaction();
        try {
            foreach ($groups as $supplierId => $groupRows) {
                $subtotal = 0;
                $lines = [];
                foreach ($groupRows as $r) {
                    // Suggested = (buffer + open demand) − available. Runway
                    // materials can carry a zero reorder buffer — restock 14
                    // days of the observed burn instead of a zero-qty line.
                    $qty = (float) $r->suggested;
                    if ($qty <= 0) {
                        $qty = max(round((float) $r->used_30d / 30 * 14 - (float) $r->available, 2), 1);
                    }
                    $price = $r->last_price !== null ? (float) $r->last_price : (float) ($r->unit_cost ?? 0);
                    $lines[] = ['row' => $r, 'qty' => $qty, 'price' => $price];
                    $subtotal += $qty * $price;
                }

                $purchaseOrder = PurchaseOrder::create([
                    'po_number'              => $this->nextPoNumber(),
                    'supplier_id'            => $supplierId,
                    'order_date'             => now()->toDateString(),
                    'expected_delivery_date' => now()->addDays(7)->toDateString(),
                    'status'                 => 'draft',
                    'currency_code'          => 'KES',
                    'subtotal'               => $subtotal,
                    'tax_amount'             => 0,
                    'shipping_amount'        => 0,
                    'total_amount'           => $subtotal,
                    'payment_status'         => 'unpaid',
                    'notes'                  => 'Draft from stock-runway suggestion',
                    'created_by'             => $request->user()->id,
                ]);

                foreach ($lines as $line) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'item_type'         => 'material',
                        'product_id'        => null,
                        'material_id'       => $line['row']->id,
                        'description'       => $line['row']->name,
                        'quantity'          => $line['qty'],
                        'quantity_received' => 0,
                        'unit_price'        => $line['price'],
                        'tax_amount'        => 0,
                        'total_price'       => $line['qty'] * $line['price'],
                    ]);
                }

                $created[] = $purchaseOrder;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to create draft purchase order(s) from suggestions.');
        }

        foreach ($created as $purchaseOrder) {
            $supplier = Supplier::find($purchaseOrder->supplier_id);
            ActivityLogService::log('created', $purchaseOrder, [
                'po_number'     => $purchaseOrder->po_number,
                'supplier_name' => $supplier?->name,
                'items_count'   => $purchaseOrder->items()->count(),
                'total_amount'  => $purchaseOrder->total_amount,
                'currency'      => $purchaseOrder->currency_code,
                'source'        => 'stock_runway_suggestion',
            ], "Created draft PO {$purchaseOrder->po_number} from stock-runway suggestion for supplier " . ($supplier?->name ?? 'Unknown'));
        }

        return response()->json([
            'message' => count($created) === 1
                ? 'Draft purchase order created from suggestions.'
                : count($created) . ' draft purchase orders created from suggestions (one per supplier).',
            'purchase_orders' => array_map(fn ($po) => [
                'id'           => $po->id,
                'po_number'    => $po->po_number,
                'supplier_id'  => $po->supplier_id,
                'total_amount' => (float) $po->total_amount,
            ], $created),
        ], 201);
    }

    // =========================================================================
    // PUT /api/v1/admin/purchase-orders/{id}
    // =========================================================================

    public function update(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);

        // The supplier's invoice arrives with or after the goods: it can be
        // recorded at any status except cancelled. Everything else keeps the
        // old window (draft / pending / approved); the owner revises the rest.
        $invoiceOnly = collect($request->except(['supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_amount', 'invoice_number']))->isEmpty();
        if (!in_array($purchaseOrder->status, ['draft', 'pending_approval', 'approved'])
            && !($invoiceOnly && $purchaseOrder->status !== 'cancelled')) {
            return response()->json([
                'message' => 'Purchase order cannot be edited in its current status.',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id'            => 'sometimes|exists:suppliers,id',
            'expected_delivery_date' => 'sometimes|date',
            'shipping_cost'          => 'nullable|numeric|min:0',
            'tax'                    => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string',
            'payment_terms'          => 'nullable|string',
            // The supplier's own invoice for this order (2026-10-16). The old
            // invoice_number had no column and was silently dropped; it now
            // lands in supplier_invoice_number.
            'invoice_number'          => 'nullable|string|max:100',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'supplier_invoice_date'   => 'nullable|date',
            'supplier_invoice_amount' => 'nullable|numeric|min:0',
        ]);

        // Map frontend field names → actual DB columns
        $updateData = [];
        foreach (['supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_amount'] as $f) {
            if (array_key_exists($f, $validated)) {
                $updateData[$f] = $validated[$f] === '' ? null : $validated[$f];
            }
        }
        if (!array_key_exists('supplier_invoice_number', $validated) && isset($validated['invoice_number'])) {
            $updateData['supplier_invoice_number'] = $validated['invoice_number'];
        }

        if (isset($validated['supplier_id']))            $updateData['supplier_id']            = $validated['supplier_id'];
        if (isset($validated['expected_delivery_date'])) $updateData['expected_delivery_date'] = $validated['expected_delivery_date'];
        if (isset($validated['notes']))                  $updateData['notes']                  = $validated['notes'];
        if (isset($validated['payment_terms']))          $updateData['payment_terms']           = $validated['payment_terms'];

        // Recalculate total if costs changed
        $shippingAmount = isset($validated['shipping_cost']) ? $validated['shipping_cost'] : $purchaseOrder->shipping_amount;
        $taxAmount      = isset($validated['tax'])           ? $validated['tax']           : $purchaseOrder->tax_amount;

        if (isset($validated['shipping_cost']) || isset($validated['tax'])) {
            $updateData['shipping_amount'] = $shippingAmount;
            $updateData['tax_amount']      = $taxAmount;
            $updateData['total_amount']    = $purchaseOrder->subtotal + $shippingAmount + $taxAmount;
        }

        // An approval is bound to what was approved (Phase 3B). Changing the
        // supplier or the total of a PO that is waiting for, or already has,
        // its approval withdraws it: back to draft, to be submitted again.
        $boundChanged = (isset($updateData['supplier_id']) && (int) $updateData['supplier_id'] !== (int) $purchaseOrder->supplier_id)
            || (isset($updateData['total_amount']) && round((float) $updateData['total_amount'], 2) !== round((float) $purchaseOrder->total_amount, 2));
        $withdrawn = $boundChanged && in_array($purchaseOrder->status, ['pending_approval', 'approved'], true);
        if ($withdrawn) {
            $updateData['status']      = 'draft';
            $updateData['approved_by'] = null;
            $updateData['approved_at'] = null;
        }

        $previousStatus = $purchaseOrder->status;
        $purchaseOrder->update($updateData);

        if ($withdrawn) {
            app(\App\Services\Approvals\ApprovalEngine::class)->cancelOpen(
                'purchase_order', $purchaseOrder, $request->user(), 'The purchase order was edited after submission.',
            );
            ActivityLogService::log('status_changed', $purchaseOrder, [
                'old_status' => $previousStatus,
                'new_status' => 'draft',
                'why'        => 'supplier or total changed — approval withdrawn',
            ], "PO {$purchaseOrder->po_number} returned to draft: edited after {$previousStatus}");
        }

        ActivityLogService::log('updated', $purchaseOrder, [
            'changed_fields' => array_keys($updateData),
        ], "Updated PO {$purchaseOrder->po_number}: " . implode(', ', array_keys($updateData)));

        return response()->json([
            'message'        => $withdrawn
                ? 'Purchase order updated. Its approval was withdrawn — submit it again.'
                : 'Purchase order updated successfully.',
            'purchase_order' => $purchaseOrder->fresh(),
        ]);
    }

    // =========================================================================
    // PATCH /api/v1/admin/purchase-orders/{id}/status
    // =========================================================================

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,pending_approval,approved,ordered,partially_received,received,cancelled',
            'notes'  => 'nullable|string',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($id);
        $oldStatus     = $purchaseOrder->status;
        $engine        = app(\App\Services\Approvals\ApprovalEngine::class);

        if ($validated['status'] === 'cancelled') {
            \App\Services\Procurement\SupplierPayables::assertCancellable($purchaseOrder);
        }

        $updateData = ['status' => $validated['status']];

        // This endpoint can move a PO to any status, so it is a second door
        // to approval: jumping a PO that was never approved to approved,
        // ordered or received IS approving it. The raiser and submitter may
        // not (maker ≠ checker), and since Phase 3B approval is signed band
        // by band through the approval engine — this door signs the band the
        // PO is waiting on, and moves the PO on only once the last band has
        // signed. Moving an already approved PO along (approved → ordered) is
        // not a second approval.
        $preApproval  = in_array($oldStatus, ['draft', 'pending_approval', 'cancelled'], true);
        $postApproval = in_array($validated['status'], ['approved', 'ordered', 'partially_received', 'received'], true);
        $approvesNow  = $preApproval && $postApproval;

        if ($approvesNow) {
            MakerChecker::assertNotMaker(
                $request->user(), 'purchase_order.approve', $purchaseOrder,
                $purchaseOrder->created_by, $purchaseOrder->submitted_by,
            );
            if ($oldStatus !== 'pending_approval') {
                return response()->json([
                    'message' => 'Submit this purchase order for approval first; it is approved band by band.',
                    'code'    => 'NOT_AWAITING_APPROVAL',
                ], 422);
            }

            $approval = $engine->sign(
                $engine->openOrAdopt('purchase_order', $purchaseOrder), $request->user(),
                \App\Models\ApprovalSignature::APPROVED, $validated['notes'] ?? null,
            );
            if ($approval->status !== \App\Models\ApprovalRequest::APPROVED) {
                return response()->json([
                    'message'         => 'Signed. It now waits for the next band before it can move on.',
                    'purchase_order'  => $purchaseOrder->fresh(),
                    'previous_status' => $oldStatus,
                    'approval'        => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
                ]);
            }
            // The engine approved it (approved_by = the final signer); now the
            // status the caller asked for.
            $purchaseOrder->refresh();
            if ($validated['status'] !== 'approved') {
                $purchaseOrder->update(['status' => $validated['status']]);
            }
        } else {
            if ($validated['status'] === 'pending_approval' && $oldStatus === 'draft') {
                $updateData['submitted_by'] = $request->user()->id;
                $updateData['submitted_at'] = now();
            }
            $purchaseOrder->update($updateData);

            if ($oldStatus === 'pending_approval' && $validated['status'] !== 'pending_approval') {
                $engine->cancelOpen('purchase_order', $purchaseOrder, $request->user(), "Status moved to {$validated['status']}.");
            }
            if ($validated['status'] === 'pending_approval' && $oldStatus !== 'pending_approval') {
                $engine->submit('purchase_order', $purchaseOrder->fresh(), $request->user());
            }
        }

        // ── Audit log ────────────────────────────────────────────────────────
        ActivityLogService::log('status_changed', $purchaseOrder, [
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
            'notes'      => $validated['notes'] ?? null,
        ], "PO {$purchaseOrder->po_number} status: {$oldStatus} → {$validated['status']}");

        // ── Notification ─────────────────────────────────────────────────────
        NotificationService::purchaseOrderStatusChanged(
            $purchaseOrder->id,
            $purchaseOrder->po_number,
            $oldStatus,
            $validated['status'],
            $purchaseOrder->created_by
        );

        return response()->json([
            'message'        => 'Status updated successfully.',
            'purchase_order' => $purchaseOrder->fresh(),
            'previous_status'=> $oldStatus,
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/receive  (GRN)
    // =========================================================================

    public function receive(Request $request, $id)
    {
        $validated = $request->validate([
            'items'                       => 'required|array|min:1',
            'items.*.po_item_id'          => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'   => 'required|numeric|min:0.01|decimal:0,2',
            'items.*.quality_status'      => 'nullable|in:passed,rejected',
            'items.*.notes'               => 'nullable|string',
            'outlet_id'                   => 'nullable|exists:outlets,id',
            'location_type'               => 'required|in:warehouse,outlet',
            'notes'                       => 'nullable|string',
        ]);

        $purchaseOrder = PurchaseOrder::with('items')->findOrFail($id);

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cannot receive items for a cancelled purchase order.'], 422);
        }

        if (!in_array($purchaseOrder->status, ['approved', 'ordered', 'partially_received'])) {
            return response()->json(['message' => 'Purchase order must be approved or ordered before receiving.'], 422);
        }

        // Whoever approved the order does not also sign for the goods.
        MakerChecker::assertNotMaker(
            $request->user(), 'purchase_order.receive', $purchaseOrder,
            $purchaseOrder->approved_by,
        );

        DB::beginTransaction();
        try {
            // Create Goods Received Note using the actual model
            $grn = GoodsReceivedNote::create([
                'purchase_order_id' => $purchaseOrder->id,
                'outlet_id'         => $validated['outlet_id'] ?? null,
                'received_date'     => now()->toDateString(),
                'notes'             => $validated['notes'] ?? null,
                'received_by'       => $request->user()->id,
            ]);

            $costNotes = [];
            foreach ($validated['items'] as $item) {
                $poItem = PurchaseOrderItem::find($item['po_item_id']);

                if (!$poItem || $poItem->purchase_order_id != $purchaseOrder->id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Purchase order item not found.'], 404);
                }

                if ($poItem->item_type === 'product' && !self::isWhole($item['quantity_received'])) {
                    DB::rollBack();
                    return response()->json(['message' => "Products are received in whole pieces: {$poItem->description}"], 422);
                }

                // In whole hundredths, not floats: ordered 0.30 less received
                // 0.10 is 0.19999999999999998 as a float, which refused the
                // final legitimate 0.20 m as "exceeds remaining".
                $remainingH = self::hundredths($poItem->quantity) - self::hundredths($poItem->quantity_received);
                $remaining  = $remainingH / 100;
                if (self::hundredths($item['quantity_received']) > $remainingH) {
                    DB::rollBack();
                    return response()->json([
                        'message'   => "Quantity received exceeds remaining quantity for item: {$poItem->description}",
                        'remaining' => $remaining,
                    ], 422);
                }

                $qualityStatus  = $item['quality_status'] ?? 'passed';
                $qtyReceived    = $item['quantity_received'];
                $qtyRejected    = $qualityStatus === 'rejected' ? $qtyReceived : 0;
                $qtyAccepted    = $qtyReceived - $qtyRejected;

                // Create GRN item using the actual model/table
                GrnItem::create([
                    'grn_id'            => $grn->id,
                    'po_item_id'        => $poItem->id,
                    'quantity_received' => $qtyReceived,
                    'quantity_rejected' => $qtyRejected,
                    'condition'         => $qualityStatus,
                    'notes'             => $item['notes'] ?? null,
                ]);

                // Update inventory for accepted quantity only
                if ($qtyAccepted > 0) {
                    $costNotes[] = $poItem->item_type === 'product'
                        ? $this->receiveProductInventory(
                            $poItem, $qtyAccepted, $validated, $purchaseOrder, $grn->grn_number, $request->user()->id
                        )
                        : $this->receiveMaterialInventory(
                            $poItem, $qtyAccepted, $validated, $purchaseOrder, $grn->grn_number, $request->user()->id
                        );
                }

                // Update quantity received on the PO item
                $poItem->increment('quantity_received', $qtyReceived);
            }

            $costNotes = array_values(array_filter($costNotes));

            // Recalculate and update PO status
            $purchaseOrder->refresh();
            $allItems        = $purchaseOrder->items;
            $fullyReceived   = $allItems->every(fn ($i) => $i->quantity_received >= $i->quantity);
            $anyReceived     = $allItems->some(fn ($i)  => $i->quantity_received > 0);

            if ($fullyReceived) {
                $purchaseOrder->update(['status' => 'received']);
            } elseif ($anyReceived) {
                $purchaseOrder->update(['status' => 'partially_received']);
            }

            DB::commit();

            // ── Audit log ─────────────────────────────────────────────────────
            ActivityLogService::log('grn_created', $purchaseOrder, [
                'grn_number'          => $grn->grn_number,
                'items_received_count'=> count($validated['items']),
                'fully_received'      => $fullyReceived,
                'outlet_id'           => $validated['outlet_id'] ?? null,
                'cost_notes'          => $costNotes,
            ], "GRN {$grn->grn_number} created against PO {$purchaseOrder->po_number} (" . ($fullyReceived ? 'fully received' : 'partial') . ")");

            // ── Notification ──────────────────────────────────────────────────
            NotificationService::goodsReceived(
                $purchaseOrder->id,
                $purchaseOrder->po_number,
                $grn->grn_number,
                $purchaseOrder->supplier?->name ?? 'Unknown',
                $fullyReceived
            );

            return response()->json([
                'message'        => trim('Goods received successfully. ' . implode(' ', $costNotes)),
                'cost_notes'     => $costNotes,
                'receipt_number' => $grn->grn_number,
                'purchase_order' => $purchaseOrder->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to receive items.');
        }
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/return
    // =========================================================================

    public function return(Request $request, $id)
    {
        $validated = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.po_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity'   => 'required|numeric|min:0.01|decimal:0,2',
            'items.*.reason'     => 'required|string|max:500',
            'notes'              => 'nullable|string',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($id);

        DB::beginTransaction();
        try {
            $returnNumber = 'PR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

            $purchaseReturn = PurchaseReturn::create([
                'return_number'     => $returnNumber,
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id'       => $purchaseOrder->supplier_id,
                'return_date'       => now()->toDateString(),
                'reason'            => $validated['notes'] ?? 'See item reasons',
                'status'            => 'pending',
                'notes'             => $validated['notes'] ?? null,
                'created_by'        => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                // Locked for the rest of the transaction. Raising a return
                // lowers quantity_received, and the next return is checked
                // against what is left — but without the lock two submits at
                // the same moment (a double tap, two staff on one PO) both
                // read the old count and both pass, returning the same goods
                // twice. The second now waits and sees the first's decrement.
                $poItem = PurchaseOrderItem::whereKey($item['po_item_id'])->lockForUpdate()->first();

                if (!$poItem || $poItem->purchase_order_id != $purchaseOrder->id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Purchase order item not found.'], 404);
                }

                if ($poItem->item_type === 'product' && !self::isWhole($item['quantity'])) {
                    DB::rollBack();
                    return response()->json(['message' => "Products are returned in whole pieces: {$poItem->description}"], 422);
                }

                if (self::hundredths($item['quantity']) > self::hundredths($poItem->quantity_received)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Cannot return more than received for: {$poItem->description}",
                    ], 422);
                }

                // Reduce inventory
                if ($poItem->item_type === 'product') {
                    // Use InventoryItem (inventory_items table) to correctly log
                    // the transaction with inventory_item_id set.
                    $invItem = null;
                    if ($poItem->product_variant_id) {
                        $invItem = InventoryItem::where('product_variant_id', $poItem->product_variant_id)->first();
                    }
                    if (!$invItem) {
                        $invItem = InventoryItem::where('product_id', $poItem->product_id)
                            ->whereNull('product_variant_id')
                            ->first();
                    }

                    if ($invItem && $invItem->quantity_on_hand >= $item['quantity']) {
                        $invItem->adjustQuantity(
                            -(int) round($item['quantity']),
                            'purchase_return',
                            \App\Models\PurchaseReturn::class,
                            $purchaseReturn->id,
                            $request->user()->id
                        );
                    }

                    // Keep the Inventory (inventories) table in sync if a row exists
                    Inventory::where('product_id', $poItem->product_id)
                        ->where('inventory_type', 'product')
                        ->when($poItem->product_variant_id, fn ($q) => $q->where('product_variant_id', $poItem->product_variant_id))
                        ->where('quantity_on_hand', '>=', $item['quantity'])
                        ->decrement('quantity_on_hand', (int) round($item['quantity']));
                } else {
                    $matInv = MaterialInventory::where('material_id', $poItem->material_id)->first();
                    if ($matInv) {
                        $matInv->adjustQuantity(
                            -(float) $item['quantity'], 'purchase_return',
                            'purchase_return', $purchaseReturn->id,
                            $request->user()->id
                        );
                    }
                }

                // Decrement quantity received on PO item
                $poItem->decrement('quantity_received', $item['quantity']);

                // Record the return item so detail view can load them
                DB::table('purchase_return_items')->insert([
                    'return_id'  => $purchaseReturn->id,
                    'po_item_id' => $item['po_item_id'],
                    'quantity'   => $item['quantity'],
                    'reason'     => $item['reason'],
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            // ── Audit log ─────────────────────────────────────────────────────
            ActivityLogService::log('purchase_return_created', $purchaseOrder, [
                'return_number' => $purchaseReturn->return_number,
                'items_count'   => count($validated['items']),
                'reason'        => $validated['notes'] ?? 'See item reasons',
            ], "Purchase return {$purchaseReturn->return_number} created against PO {$purchaseOrder->po_number}");

            // ── Notification ──────────────────────────────────────────────────
            NotificationService::purchaseReturnCreated(
                $purchaseOrder->id,
                $purchaseOrder->po_number,
                $purchaseReturn->return_number,
                $purchaseOrder->supplier?->name ?? 'Unknown'
            );

            return response()->json([
                'message'       => 'Purchase return processed successfully.',
                'return_number' => $purchaseReturn->return_number,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to process return.');
        }
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/submit
    // Submits a draft PO for approval. Only the creator or admin can do this.
    // =========================================================================

    public function submit(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft purchase orders can be submitted for approval.'], 422);
        }

        if ($purchaseOrder->items()->count() === 0) {
            return response()->json(['message' => 'Cannot submit a purchase order with no items.'], 422);
        }

        // Into the approval engine: bands by value in KES at the reporting
        // rate, judged on the rolling 24h total to this supplier (Phase 3B).
        // A PO rejected or expired before comes back as the next version.
        $approval = DB::transaction(function () use ($purchaseOrder, $request) {
            $purchaseOrder->update([
                'status'       => 'pending_approval',
                'submitted_by'  => $request->user()->id,
                'submitted_at'  => now(),
            ]);

            return app(\App\Services\Approvals\ApprovalEngine::class)
                ->submit('purchase_order', $purchaseOrder->fresh(), $request->user());
        });

        ActivityLogService::log('status_changed', $purchaseOrder, [
            'old_status'          => 'draft',
            'new_status'          => 'pending_approval',
            'approval_request_id' => $approval->id,
        ], "PO {$purchaseOrder->po_number} submitted for approval");

        NotificationService::purchaseOrderStatusChanged(
            $purchaseOrder->id,
            $purchaseOrder->po_number,
            'draft',
            'pending_approval',
            null
        );

        return response()->json([
            'message'        => 'Purchase order submitted for approval.',
            'purchase_order' => $purchaseOrder->fresh(),
            'approval'       => app(\App\Services\Approvals\ApprovalEngine::class)->present($approval->load(['signatures', 'maker']), $request->user()),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/approve
    // Approves a pending PO. Requires admin / procurement_manager role.
    // =========================================================================

    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'notes'   => 'nullable|string|max:1000',
            'version' => 'nullable|integer|min:1',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if ($purchaseOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only purchase orders pending approval can be approved.'], 422);
        }

        // Since Phase 3B this endpoint SIGNS the band the PO is waiting on.
        // The engine decides whether the caller holds that band, refuses the
        // raiser and submitter (maker ≠ checker, super admin included) and
        // approves the PO only when the last band has signed.
        $engine   = app(\App\Services\Approvals\ApprovalEngine::class);
        $approval = $engine->sign(
            $engine->openOrAdopt('purchase_order', $purchaseOrder), $request->user(),
            \App\Models\ApprovalSignature::APPROVED, $validated['notes'] ?? null,
            $purchaseOrder->id, $validated['version'] ?? null,
        );
        $done = $approval->status === \App\Models\ApprovalRequest::APPROVED;

        return response()->json([
            'message'        => $done
                ? "Purchase order {$purchaseOrder->po_number} approved."
                : "Signed. Purchase order {$purchaseOrder->po_number} now waits for the next band.",
            'purchase_order' => $purchaseOrder->fresh(['approvedBy:id,first_name,last_name']),
            'approval'       => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/reject
    // Rejects a pending PO, returning it to draft with a rejection note.
    // =========================================================================

    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if ($purchaseOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only purchase orders pending approval can be rejected.'], 422);
        }

        // A rejection is a decision at the band the PO is waiting on; the
        // engine returns the PO to draft for its maker (PurchaseOrderHandler).
        $engine   = app(\App\Services\Approvals\ApprovalEngine::class);
        $approval = $engine->sign(
            $engine->openOrAdopt('purchase_order', $purchaseOrder), $request->user(),
            \App\Models\ApprovalSignature::REJECTED, $validated['reason'], $purchaseOrder->id,
        );

        return response()->json([
            'message'        => "Purchase order {$purchaseOrder->po_number} rejected and returned to draft.",
            'purchase_order' => $purchaseOrder->fresh(),
            'approval'       => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/cancel
    // Cancels a PO. Cannot cancel once receiving has started.
    // =========================================================================

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if (in_array($purchaseOrder->status, ['received', 'partially_received'])) {
            return response()->json([
                'message' => 'Cannot cancel a purchase order that has been fully or partially received.',
            ], 422);
        }

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json(['message' => 'Purchase order is already cancelled.'], 422);
        }

        \App\Services\Procurement\SupplierPayables::assertCancellable($purchaseOrder);

        $purchaseOrder->update([
            'status' => 'cancelled',
            'notes'  => "CANCELLED: {$validated['reason']}\n\n" . ($purchaseOrder->notes ?? ''),
        ]);
        app(\App\Services\Approvals\ApprovalEngine::class)->cancelOpen(
            'purchase_order', $purchaseOrder, $request->user(), "Cancelled: {$validated['reason']}",
        );

        ActivityLogService::log('cancelled', $purchaseOrder, [
            'reason'       => $validated['reason'],
            'cancelled_by' => auth()->id(),
            'old_status'   => $purchaseOrder->getOriginal('status') ?? $purchaseOrder->status,
        ], "PO {$purchaseOrder->po_number} cancelled: {$validated['reason']}");

        NotificationService::purchaseOrderStatusChanged(
            $purchaseOrder->id,
            $purchaseOrder->po_number,
            $purchaseOrder->status,
            'cancelled',
            $purchaseOrder->created_by
        );

        return response()->json([
            'message'        => "Purchase order {$purchaseOrder->po_number} cancelled.",
            'purchase_order' => $purchaseOrder->fresh(),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-returns/{id}/approve
    // Approves a pending purchase return.
    // =========================================================================

    public function approvePurchaseReturn(Request $request, $id)
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:1000']);

        $user = $request->user();
        if (!$user->can('procurement.approve')) {
            return response()->json(['message' => 'You do not have permission to approve purchase returns.'], 403);
        }

        $return = PurchaseReturn::where('status', 'pending')->findOrFail($id);

        MakerChecker::assertNotMaker($user, 'purchase_return.approve', $return, $return->created_by);

        // Conditional on still being pending: an approve racing a reject must
        // not land on a return whose stock the reject has just put back.
        $moved = PurchaseReturn::whereKey($return->id)->where('status', 'pending')->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'notes'       => $validated['notes'] ?? $return->notes,
        ]);
        if ($moved === 0) {
            return response()->json(['message' => 'This return was already decided by someone else.'], 409);
        }

        return response()->json([
            'message' => "Purchase return {$return->return_number} approved.",
            'return'  => $return->fresh(),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-returns/{id}/reject
    // Rejects a pending purchase return.
    // =========================================================================

    public function rejectPurchaseReturn(Request $request, $id)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $user = $request->user();
        if (!$user->can('procurement.approve')) {
            return response()->json(['message' => 'You do not have permission to reject purchase returns.'], 403);
        }

        $return = PurchaseReturn::where('status', 'pending')->findOrFail($id);

        // Raising a return took the goods out of stock and lowered the PO
        // line's received count straight away. Rejecting used to change only
        // the status, so goods that never left the building vanished from
        // stock for good. Reverse exactly what was taken, inside one
        // transaction, with the return row locked so it can only happen once.
        DB::transaction(function () use ($return, $validated, $user) {
            $locked = PurchaseReturn::whereKey($return->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                abort(response()->json(['message' => 'This return was already decided by someone else.'], 409));
            }

            $this->reverseReturnStock($locked, $user->id);

            $locked->update([
                'status' => 'rejected',
                'notes'  => "REJECTED: {$validated['reason']}\n\n" . ($locked->notes ?? ''),
            ]);
        });

        ActivityLogService::log('purchase_return_rejected', $return, [
            'return_number' => $return->return_number,
            'reason'        => $validated['reason'],
        ], "Purchase return {$return->return_number} rejected; stock and received quantities restored");

        return response()->json([
            'message' => "Purchase return {$return->return_number} rejected.",
            'return'  => $return->fresh(),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-returns/{id}/complete
    // =========================================================================

    public function completePurchaseReturn(Request $request, $id)
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:2000']);

        $return = PurchaseReturn::where('status', 'approved')->findOrFail($id);

        $return->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'notes'        => trim(
                ($return->notes ?? '') .
                ($validated['notes'] ? "\n\nCompletion notes: {$validated['notes']}" : '')
            ),
        ]);

        ActivityLogService::log('completed', $return, [
            'return_number' => $return->return_number,
            'notes'         => $validated['notes'] ?? null,
        ], "Purchase return {$return->return_number} marked as completed");

        return response()->json([
            'message' => "Purchase return {$return->return_number} completed.",
            'return'  => $return->fresh(),
        ]);
    }


    // =========================================================================
    // DELETE /api/v1/admin/purchase-orders/{id}
    // =========================================================================

    public function destroy($id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if (!in_array($purchaseOrder->status, ['draft', 'cancelled'])) {
            return response()->json([
                'message' => 'Only draft or cancelled purchase orders can be deleted.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            PurchaseOrderItem::where('purchase_order_id', $purchaseOrder->id)->delete();
            $purchaseOrder->delete();

            DB::commit();

            return response()->json(['message' => 'Purchase order deleted successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return ServerError::respond($e, 'Failed to delete purchase order.');
        }
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-returns
    // =========================================================================

    public function purchaseReturns(Request $request)
    {
        $query = PurchaseReturn::with([
                'purchaseOrder:id,po_number,supplier_id',
                'purchaseOrder.supplier:id,name',
                'supplier:id,name',
            ])
            ->withCount('returnItems as items_count')
            ->orderByDesc('created_at');

        // Status filter - frontend sends ?status=pending for the Approvals page
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('search')) {
            $query->where('return_number', 'ILIKE', '%' . $request->search . '%');
        }

        $perPage = min((int) $request->get('per_page', 20), 100);

        try {
            $returns = $query->paginate($perPage);
        } catch (\Exception $e) {
            // Fallback if purchase_return_items table doesn't exist yet
            $returns = PurchaseReturn::with([
                'purchaseOrder:id,po_number,supplier_id',
                'purchaseOrder.supplier:id,name',
                'supplier:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
        }

        return response()->json($returns);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-returns/{id}
    // =========================================================================

    public function purchaseReturnDetails($id)
    {
        $return = PurchaseReturn::with([
            'purchaseOrder:id,po_number,supplier_id',
            'purchaseOrder.supplier:id,name',
            'supplier:id,name',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        // Load return items + their PO item details (no Eloquent model, use raw query)
        $items = DB::table('purchase_return_items as ri')
            ->join('purchase_order_items as poi', 'ri.po_item_id', '=', 'poi.id')
            ->leftJoin('products as p', 'poi.product_id', '=', 'p.id')
            ->leftJoin('product_translations as pt', function ($j) {
                $j->on('pt.product_id', '=', 'p.id')->where('pt.language_code', '=', 'en');
            })
            ->leftJoin('materials as m', 'poi.material_id', '=', 'm.id')
            ->where('ri.return_id', $return->id)
            ->select(
                'ri.id',
                'ri.po_item_id',
                'ri.quantity',
                'ri.reason',
                'poi.item_type',
                'poi.description',
                'poi.product_id',
                'poi.material_id',
                'poi.unit_price',
                'p.sku as product_sku',
                DB::raw("COALESCE(pt.name, p.sku, 'Unknown Product') as product_name"),
                'm.name as material_name'
            )
            ->get()
            ->map(fn ($row) => [
                'id'         => $row->id,
                'po_item_id' => $row->po_item_id,
                'quantity'   => (float) $row->quantity,
                'reason'     => $row->reason,
                'purchase_order_item' => [
                    'item_type'   => $row->item_type,
                    'description' => $row->description,
                    'unit_price'  => (float) $row->unit_price,
                    'product'     => $row->product_id ? [
                        'name' => $row->product_name,
                        'sku'  => $row->product_sku,
                    ] : null,
                    'material'    => $row->material_id ? [
                        'name' => $row->material_name,
                    ] : null,
                ],
            ]);

        $data = $return->toArray();
        $data['items'] = $items;

        return response()->json(['return' => $data]);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-returns/{id}/audit-log
    // =========================================================================

    public function purchaseReturnAuditLog($id)
    {
        PurchaseReturn::findOrFail($id); // 404 guard

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where(function ($q) use ($id) {
                $q->where(function ($q2) use ($id) {
                    $q2->where('al.subject_type', \App\Models\PurchaseReturn::class)
                       ->where('al.subject_id', $id);
                })->orWhereRaw("al.properties::text LIKE ?", ["%\"return_number\":%"])
                  ->where('al.event', 'like', '%purchase_return%');
            })
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id', 'al.event', 'al.action', 'al.description',
                'al.properties', 'al.ip_address', 'al.created_at',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
                'u.email as actor_email'
            )
            ->get()
            ->map(function ($log) {
                $labels = [
                    'purchase_return_created'  => 'Return Created',
                    'created'                  => 'Return Created',
                    'approved'                 => 'Return Approved',
                    'rejected'                 => 'Return Rejected',
                    'cancelled'                => 'Return Cancelled',
                    'completed'                => 'Return Completed',
                ];
                $event = $log->event ?? $log->action ?? '';
                return [
                    'id'          => $log->id,
                    'event'       => $event,
                    'label'       => $labels[$event] ?? ucfirst(str_replace('_', ' ', $event)),
                    'description' => $log->description,
                    'properties'  => $log->properties ? json_decode($log->properties, true) : [],
                    'actor_name'  => $log->actor_name,
                    'actor_email' => $log->actor_email,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at,
                ];
            });

        return response()->json(['logs' => $logs]);
    }

    // =========================================================================
    // GET /api/v1/admin/grn
    // =========================================================================

    public function grnIndex(Request $request)
    {
        $query = GoodsReceivedNote::with([
            'purchaseOrder:id,po_number,supplier_id',
            'purchaseOrder.supplier:id,name',
            'receivedBy:id,first_name,last_name',
            'outlet:id,name',
        ])->orderByDesc('received_date');

        if ($request->filled('supplier_id')) {
            $query->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $request->supplier_id));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('grn_number', 'like', "%{$search}%")
                  ->orWhereHas('purchaseOrder', fn ($q2) => $q2->where('po_number', 'like', "%{$search}%"));
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);

        $paginated = $query->paginate($perPage);

        // Transform each GRN to a flat, frontend-friendly shape
        $paginated->getCollection()->transform(function (GoodsReceivedNote $grn) {
            $po       = $grn->purchaseOrder;
            $recBy    = $grn->receivedBy;
            $outlet   = $grn->outlet;

            return [
                'id'                  => $grn->id,
                'grn_number'          => $grn->grn_number,
                'purchase_order_id'   => $grn->purchase_order_id,
                'po_number'           => $po?->po_number,
                'supplier_name'       => $po?->supplier?->name,
                'received_date'       => $grn->received_date?->toDateString(),
                'location_type'       => $grn->outlet_id ? 'outlet' : 'warehouse',
                'outlet_id'           => $grn->outlet_id,
                'outlet_name'         => $outlet?->name,
                'notes'               => $grn->notes,
                'invoice_number'      => $grn->invoice_number,
                'received_by'         => $recBy ? [
                    'id'   => $recBy->id,
                    'name' => trim("{$recBy->first_name} {$recBy->last_name}"),
                ] : null,
                'created_at'          => $grn->created_at,
            ];
        });

        return response()->json($paginated);
    }

    // =========================================================================
    // GET /api/v1/admin/grn/{id}
    // =========================================================================

    public function grnShow($id)
    {
        $grn = GoodsReceivedNote::with([
            'purchaseOrder.supplier',
            'items.purchaseOrderItem.product.translations' => fn ($q) => $q->where('language_code', 'en'),
            'items.purchaseOrderItem.material',
            'receivedBy:id,first_name,last_name',
        ])->findOrFail($id);

        return response()->json(['grn' => $grn]);
    }

    // =========================================================================
    // GET /api/v1/admin/purchase-orders/{id}/audit-log
    // =========================================================================

    public function auditLog($id)
    {
        $po = PurchaseOrder::findOrFail($id);

        $logs = \Illuminate\Support\Facades\DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where('al.subject_type', \App\Models\PurchaseOrder::class)
            ->where('al.subject_id', $po->id)
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id', 'al.event', 'al.action', 'al.description',
                'al.properties', 'al.ip_address', 'al.created_at',
                \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
                'u.email as actor_email', 'u.id as actor_id'
            )
            ->get()
            ->map(function ($log) {
                $props = $log->properties ? json_decode($log->properties, true) : [];
                $labels = [
                    'created'                  => 'PO Created',
                    'updated'                  => 'PO Updated',
                    'status_changed'           => 'Status Changed',
                    'approved'                 => 'PO Approved',
                    'rejected'                 => 'PO Rejected',
                    'cancelled'                => 'PO Cancelled',
                    'grn_created'              => 'Goods Received',
                    'purchase_return_created'  => 'Purchase Return Created',
                    'item_added'               => 'Item Added',
                    'item_updated'             => 'Item Updated',
                ];
                $event = $log->event ?? $log->action ?? '';
                return [
                    'id'          => $log->id,
                    'event'       => $event,
                    'label'       => $labels[$event] ?? ucfirst(str_replace('_', ' ', $event)),
                    'description' => $log->description,
                    'properties'  => $props,
                    'actor_name'  => $log->actor_name,
                    'actor_email' => $log->actor_email,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at,
                ];
            });

        return response()->json(['logs' => $logs]);
    }

    // =========================================================================
    // GET /api/v1/admin/grn/{id}/audit-log
    // =========================================================================

    public function grnAuditLog($id)
    {
        $grn = GoodsReceivedNote::findOrFail($id);

        $logs = \Illuminate\Support\Facades\DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where('al.subject_type', \App\Models\GoodsReceivedNote::class)
            ->where('al.subject_id', $grn->id)
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id', 'al.event', 'al.action', 'al.description',
                'al.properties', 'al.ip_address', 'al.created_at',
                \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
                'u.email as actor_email'
            )
            ->get()
            ->map(fn ($log) => [
                'id'          => $log->id,
                'event'       => $log->event ?? $log->action,
                'label'       => ucfirst(str_replace('_', ' ', $log->event ?? $log->action ?? '')),
                'description' => $log->description,
                'properties'  => $log->properties ? json_decode($log->properties, true) : [],
                'actor_name'  => $log->actor_name,
                'actor_email' => $log->actor_email,
                'ip_address'  => $log->ip_address,
                'created_at'  => $log->created_at,
            ]);

        return response()->json(['logs' => $logs]);
    }

    // =========================================================================
    // Owner edits: revisions (2026-10-16)
    // =========================================================================

    /**
     * PUT /api/v1/admin/purchase-orders/{id}/revise — the owner edits the
     * whole order (header and lines) at any status but cancelled, with a
     * reason. The order as it was is kept as a revision. See
     * App\Services\Procurement\PurchaseDocumentEditor.
     */
    public function revise(Request $request, $id, \App\Services\Procurement\PurchaseDocumentEditor $editor)
    {
        $this->assertOwner($request);
        $validated = $request->validate([
            'reason'                     => 'required|string|min:5|max:500',
            'supplier_id'                => 'sometimes|exists:suppliers,id',
            'expected_delivery_date'     => 'sometimes|date',
            'payment_terms'              => 'nullable|string',
            'notes'                      => 'nullable|string',
            'shipping_cost'              => 'nullable|numeric|min:0',
            'tax'                        => 'nullable|numeric|min:0',
            'supplier_invoice_number'    => 'nullable|string|max:100',
            'supplier_invoice_date'      => 'nullable|date',
            'supplier_invoice_amount'    => 'nullable|numeric|min:0',
            'items'                      => 'required|array|min:1',
            'items.*.id'                 => 'nullable|integer',
            'items.*.type'               => 'required|in:product,material',
            'items.*.item_id'            => 'required|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity'           => 'required|numeric|min:0.01|decimal:0,2',
            'items.*.unit_price'         => 'required|numeric|min:0',
        ]);

        $result = $editor->revisePurchaseOrder(PurchaseOrder::findOrFail($id), $validated, $request->user());

        return response()->json([
            'message'        => 'Purchase order revised (Rev ' . $result['purchase_order']->revision . ').',
            'purchase_order' => $result['purchase_order'],
            'changes'        => $result['changes'],
            'notes'          => $result['notes'],
        ]);
    }

    /** GET /api/v1/admin/purchase-orders/{id}/revisions — every earlier version, newest first. */
    public function revisions($id)
    {
        $po = PurchaseOrder::findOrFail($id);

        return response()->json([
            'current_revision' => (int) $po->revision,
            'revisions'        => \App\Models\DocumentRevision::listFor(\App\Models\DocumentRevision::PURCHASE_ORDER, $po->id),
        ]);
    }

    /**
     * POST /api/v1/admin/grn/{id}/corrections — the owner corrects received
     * quantities. Stock moves by the difference (with serials), the order's
     * received counts and status follow, and the receipt as it was is kept.
     */
    public function correctGrn(Request $request, $id, \App\Services\Procurement\PurchaseDocumentEditor $editor)
    {
        $this->assertOwner($request);
        $validated = $request->validate([
            'reason'                    => 'required|string|min:5|max:500',
            'lines'                     => 'required|array|min:1',
            'lines.*.grn_item_id'       => 'required|integer',
            'lines.*.quantity_received' => 'required|numeric|min:0|decimal:0,2',
        ]);

        $result = $editor->correctGoodsReceipt(GoodsReceivedNote::findOrFail($id), $validated['lines'], $validated['reason'], $request->user());

        return response()->json([
            'message' => 'Goods receipt corrected (Rev ' . $result['grn']->revision . ').',
            'grn'     => $result['grn'],
            'changes' => $result['changes'],
        ]);
    }

    /** GET /api/v1/admin/grn/{id}/revisions */
    public function grnRevisions($id)
    {
        $grn = GoodsReceivedNote::findOrFail($id);

        return response()->json([
            'current_revision' => (int) $grn->revision,
            'revisions'        => \App\Models\DocumentRevision::listFor(\App\Models\DocumentRevision::GOODS_RECEIPT, $grn->id),
        ]);
    }

    /**
     * GET /grn/{id}/pdf and POST /grn/{id}/print were routed to methods that
     * never existed (a 500 on every press). Both now answer the receipt's PDF,
     * the same document as /pdf/grn/{id}.
     */
    public function grnPDF($id)
    {
        return app(\App\Http\Controllers\Api\DocumentPdfController::class)->grn((int) $id);
    }

    public function printGRN($id)
    {
        return $this->grnPDF($id);
    }

    private function assertOwner(Request $request): void
    {
        if (!$request->user()?->hasRole('super_admin', 'sanctum')) {
            abort(response()->json(['message' => 'Only the owner (super admin) edits issued purchase documents.'], 403));
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function receiveProductInventory(
        PurchaseOrderItem $poItem,
        float $qty,
        array $validated,
        PurchaseOrder $purchaseOrder,
        string $grnNumber,
        int $userId
    ): ?string {
        $outletId   = $validated['outlet_id'] ?? null;
        $qtyInt     = (int) round($qty);

        // The finished-goods row the units land on + its outlet, for serializing.
        $serialItem   = null;
        $serialOutlet = null;

        if ($outletId) {
            // ── Outlet-specific receive ───────────────────────────────────────
            // Update the InventoryItem for this specific outlet.
            $inventoryItem = null;

            if ($poItem->product_variant_id) {
                $inventoryItem = InventoryItem::firstOrCreate(
                    ['product_variant_id' => $poItem->product_variant_id, 'outlet_id' => $outletId],
                    ['product_id' => $poItem->product_id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]
                );
            }

            if (!$inventoryItem) {
                $inventoryItem = InventoryItem::firstOrCreate(
                    ['product_id' => $poItem->product_id, 'product_variant_id' => null, 'outlet_id' => $outletId],
                    ['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]
                );
            }

            $inventoryItem->adjustQuantity($qtyInt, 'purchase', \App\Models\PurchaseOrder::class, $purchaseOrder->id, $userId);
            $serialItem   = $inventoryItem;
            $serialOutlet = $outletId;

        } else {
            // ── Warehouse receive (no specific outlet) ────────────────────────
            // Update the warehouse InventoryItem row (outlet_id = null) - this
            // is what the POS fallback reads when no outlet-specific row exists.
            $warehouseItem = null;

            if ($poItem->product_variant_id) {
                $warehouseItem = InventoryItem::firstOrCreate(
                    ['product_variant_id' => $poItem->product_variant_id, 'outlet_id' => null],
                    ['product_id' => $poItem->product_id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]
                );
            }

            if (!$warehouseItem) {
                $warehouseItem = InventoryItem::firstOrCreate(
                    ['product_id' => $poItem->product_id, 'product_variant_id' => null, 'outlet_id' => null],
                    ['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]
                );
            }

            $warehouseItem->adjustQuantity($qtyInt, 'purchase', \App\Models\PurchaseOrder::class, $purchaseOrder->id, $userId);
            // Mint serials once, at the warehouse (outlet_id = null). A null-outlet
            // serial is sellable from any outlet, so we never duplicate per outlet.
            $serialItem   = $warehouseItem;
            $serialOutlet = null;

            // The received units live in ONE place — the warehouse row. POS already
            // treats the warehouse (null-outlet) row as a sell-from candidate for
            // every outlet, so the units are immediately sellable everywhere as a
            // single shared pool. (A previous version also `+= qty` to every
            // outlet row, which made the same physical units independently sellable
            // at each location — a systemic N× over-count / oversell. Removed.)
        }

        // Serialize the received units: every bought-in unit gets its own unique
        // code straight into stock, so purchased goods can be sold, dispatched,
        // and reconciled for loss exactly like manufactured ones. Idempotent per
        // GRN line — re-running the same receipt never duplicates serials.
        if ($serialItem) {
            ProductSerialService::receiveIntoStock(
                $poItem->product_id,
                $poItem->product_variant_id,
                $serialOutlet,
                $serialItem->id,
                $qtyInt,
                'grn:' . $grnNumber . ':item:' . $poItem->id,
                $userId,
            );
        }

        // Keep the legacy inventories table in sync if rows exist there.
        Inventory::where('inventory_type', 'product')
            ->where('product_id', $poItem->product_id)
            ->when($poItem->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $poItem->product_variant_id)
            )
            ->when($outletId,
                fn ($q) => $q->where('outlet_id', $outletId),
                fn ($q) => $q->whereNull('outlet_id')  // warehouse row only — never fan out
            )
            ->increment('quantity_on_hand', $qtyInt);

        return $this->setLatestCost($poItem, $purchaseOrder);
    }

    private function receiveMaterialInventory(
        PurchaseOrderItem $poItem,
        float $qty,
        array $validated,
        PurchaseOrder $purchaseOrder,
        string $grnNumber,
        int $userId
    ): ?string {
        $matInv = MaterialInventory::firstOrCreate(
            [
                'material_id' => $poItem->material_id,
                'outlet_id'   => $validated['outlet_id'] ?? null,
            ],
            ['quantity_on_hand' => 0]
        );

        $matInv->adjustQuantity(
            $qty, 'purchase',
            'purchase_order', $purchaseOrder->id,
            $userId
        );

        // A free line (price 0) leaves the material's cost as it was.
        return $poItem->unit_price > 0 ? $this->setLatestCost($poItem, $purchaseOrder) : null;
    }

    /**
     * The received line's price becomes the item's latest cost, in KES at the
     * reporting rate (LatestCost). Returns a note when the PO's currency has
     * no reporting rate and the cost was left as it was.
     */
    private function setLatestCost(PurchaseOrderItem $poItem, PurchaseOrder $purchaseOrder): ?string
    {
        $currency = (string) ($purchaseOrder->currency_code ?: 'KES');
        $kes      = LatestCost::kes((float) $poItem->unit_price, $currency);
        if ($kes === null) {
            return LatestCost::noRateNote($poItem, $currency);
        }
        LatestCost::set($poItem, $kes);

        return null;
    }

    /**
     * A quantity in whole hundredths. Material quantities carry 2 decimals
     * (0.25 m), and float subtraction on those is inexact — comparisons and
     * remaining-balance checks are done on these integers instead.
     */
    private static function hundredths($qty): int
    {
        return (int) round(((float) $qty) * 100);
    }

    /** Products are counted in pieces: 2.5 of a product is never valid. */
    private static function isWhole($qty): bool
    {
        return self::hundredths($qty) % 100 === 0;
    }

    /**
     * Undo what raising $return did to stock and to the PO lines.
     *
     * Driven by the LEDGER, not re-derived: every stock movement the return
     * made was logged against it (inventory_transactions with reference
     * PurchaseReturn::class, material_transactions with reference
     * 'purchase_return'), so the reversal is the exact negation of those rows
     * — including the cases where raising the return skipped a decrement
     * (no stock row, or not enough on hand), which have nothing to reverse.
     * The legacy inventories mirror follows the same ledger figures.
     */
    private function reverseReturnStock(PurchaseReturn $return, int $userId): void
    {
        $productMoves = \App\Models\InventoryTransaction::where('reference_type', PurchaseReturn::class)
            ->where('reference_id', $return->id)
            ->where('transaction_type', 'purchase_return')
            ->get();

        foreach ($productMoves as $move) {
            $inv = InventoryItem::whereKey($move->inventory_item_id)->lockForUpdate()->first();
            if (!$inv) {
                continue;
            }
            $qty = (int) -$move->quantity_change;   // logged negative
            $inv->adjustQuantity($qty, 'purchase_return_reversed', PurchaseReturn::class, $return->id, $userId);

            Inventory::where('product_id', $inv->product_id)
                ->where('inventory_type', 'product')
                ->when($inv->product_variant_id, fn ($q) => $q->where('product_variant_id', $inv->product_variant_id))
                ->increment('quantity_on_hand', $qty);
        }

        $materialMoves = MaterialTransaction::where('reference_type', 'purchase_return')
            ->where('reference_id', $return->id)
            ->where('transaction_type', 'purchase_return')
            ->get();

        foreach ($materialMoves as $move) {
            $inv = MaterialInventory::whereKey($move->material_inventory_id)->lockForUpdate()->first();
            if ($inv) {
                $inv->adjustQuantity(-(float) $move->quantity_change, 'purchase_return_reversed', 'purchase_return', $return->id, $userId);
            }
        }

        // Raising the return lowered each line's received count; restore it.
        $lines = DB::table('purchase_return_items')->where('return_id', $return->id)->get();
        foreach ($lines as $line) {
            PurchaseOrderItem::whereKey($line->po_item_id)->lockForUpdate()->first()
                ?->increment('quantity_received', $line->quantity);
        }
    }
}