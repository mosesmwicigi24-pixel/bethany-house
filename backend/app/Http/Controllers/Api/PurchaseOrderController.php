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
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\ProductVariant;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\ProductSerialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'currency'                => 'required|in:KES,USD,EUR,GBP',
            'shipping_cost'           => 'nullable|numeric|min:0',
            'tax'                     => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string',
            'payment_terms'           => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.type'            => 'required|in:product,material',
            'items.*.item_id'         => 'required|integer',
            'items.*.quantity'        => 'required|numeric|min:0.001',
            'items.*.unit_price'      => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $shippingAmount = $validated['shipping_cost'] ?? 0;
            $taxAmount      = $validated['tax'] ?? 0;
            $subtotal       = 0;

            // Pre-validate all items before creating anything
            $itemDetails = [];
            foreach ($validated['items'] as $item) {
                if ($item['type'] === 'product') {
                    $product = Product::find($item['item_id']);
                    if (!$product) {
                        DB::rollBack();
                        return response()->json(['message' => "Product ID {$item['item_id']} not found."], 422);
                    }
                    $itemDetails[] = array_merge($item, ['label' => $product->sku ?? "Product #{$item['item_id']}"]);
                } else {
                    $material = Material::find($item['item_id']);
                    if (!$material) {
                        DB::rollBack();
                        return response()->json(['message' => "Material ID {$item['item_id']} not found."], 422);
                    }
                    $itemDetails[] = array_merge($item, ['label' => $material->name]);
                }
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $totalAmount = $subtotal + $shippingAmount + $taxAmount;

            // PO number generated by model boot, but we pass it explicitly for uniqueness safety
            $poNumber = 'PO-' . date('Ymd') . '-' . str_pad(
                PurchaseOrder::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT
            );
            while (PurchaseOrder::where('po_number', $poNumber)->exists()) {
                $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4));
            }

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
            return response()->json([
                'message' => 'Failed to create purchase order.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // PUT /api/v1/admin/purchase-orders/{id}
    // =========================================================================

    public function update(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if (!in_array($purchaseOrder->status, ['draft', 'pending_approval', 'approved'])) {
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
            'invoice_number'         => 'nullable|string|max:100',
        ]);

        // Map frontend field names → actual DB columns
        $updateData = [];

        if (isset($validated['supplier_id']))            $updateData['supplier_id']            = $validated['supplier_id'];
        if (isset($validated['expected_delivery_date'])) $updateData['expected_delivery_date'] = $validated['expected_delivery_date'];
        if (isset($validated['notes']))                  $updateData['notes']                  = $validated['notes'];
        if (isset($validated['payment_terms']))          $updateData['payment_terms']           = $validated['payment_terms'];
        if (isset($validated['invoice_number']))         $updateData['invoice_number']          = $validated['invoice_number'];

        // Recalculate total if costs changed
        $shippingAmount = isset($validated['shipping_cost']) ? $validated['shipping_cost'] : $purchaseOrder->shipping_amount;
        $taxAmount      = isset($validated['tax'])           ? $validated['tax']           : $purchaseOrder->tax_amount;

        if (isset($validated['shipping_cost']) || isset($validated['tax'])) {
            $updateData['shipping_amount'] = $shippingAmount;
            $updateData['tax_amount']      = $taxAmount;
            $updateData['total_amount']    = $purchaseOrder->subtotal + $shippingAmount + $taxAmount;
        }

        $purchaseOrder->update($updateData);

        ActivityLogService::log('updated', $purchaseOrder, [
            'changed_fields' => array_keys($updateData),
        ], "Updated PO {$purchaseOrder->po_number}: " . implode(', ', array_keys($updateData)));

        return response()->json([
            'message'        => 'Purchase order updated successfully.',
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

        $updateData = ['status' => $validated['status']];

        // Set approved_at when approving
        if ($validated['status'] === 'approved') {
            $updateData['approved_by'] = $request->user()->id;
            $updateData['approved_at'] = now();
        }

        $purchaseOrder->update($updateData);

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
            'items.*.quantity_received'   => 'required|numeric|min:0.001',
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

            foreach ($validated['items'] as $item) {
                $poItem = PurchaseOrderItem::find($item['po_item_id']);

                if (!$poItem || $poItem->purchase_order_id != $purchaseOrder->id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Purchase order item not found.'], 404);
                }

                $remaining = $poItem->quantity - $poItem->quantity_received;
                if ($item['quantity_received'] > $remaining) {
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
                    if ($poItem->item_type === 'product') {
                        $this->receiveProductInventory(
                            $poItem, $qtyAccepted, $validated, $purchaseOrder, $grn->grn_number, $request->user()->id
                        );
                    } else {
                        $this->receiveMaterialInventory(
                            $poItem, $qtyAccepted, $validated, $purchaseOrder, $grn->grn_number, $request->user()->id
                        );
                    }
                }

                // Update quantity received on the PO item
                $poItem->increment('quantity_received', $qtyReceived);
            }

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
                'message'        => 'Goods received successfully.',
                'receipt_number' => $grn->grn_number,
                'purchase_order' => $purchaseOrder->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to receive items.',
                'error'   => $e->getMessage(),
            ], 500);
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
            'items.*.quantity'   => 'required|numeric|min:0.001',
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
                $poItem = PurchaseOrderItem::find($item['po_item_id']);

                if (!$poItem || $poItem->purchase_order_id != $purchaseOrder->id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Purchase order item not found.'], 404);
                }

                if ($item['quantity'] > $poItem->quantity_received) {
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
            return response()->json([
                'message' => 'Failed to process return.',
                'error'   => $e->getMessage(),
            ], 500);
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

        $purchaseOrder->update([
            'status'       => 'pending_approval',
            'submitted_by'  => $request->user()->id,
            'submitted_at'  => now(),
        ]);

        ActivityLogService::log('status_changed', $purchaseOrder, [
            'old_status' => 'draft',
            'new_status' => 'pending_approval',
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
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/purchase-orders/{id}/approve
    // Approves a pending PO. Requires admin / procurement_manager role.
    // =========================================================================

    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:1000']);

        $purchaseOrder = PurchaseOrder::findOrFail($id);

        if ($purchaseOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only purchase orders pending approval can be approved.'], 422);
        }

        // Was a hardcoded role list (super_admin/admin/procurement_manager)
        // that silently excluded procurement_officer even though that role
        // is explicitly granted procurement.approve (see SyncPermissions)
        // and the route middleware above already lets them through - this
        // redundant check then rejected them anyway. Checking the actual
        // permission keeps this in sync with whoever the route grants.
        $user = $request->user();
        if (!$user->can('procurement.approve')) {
            return response()->json(['message' => 'You do not have permission to approve purchase orders.'], 403);
        }

        $purchaseOrder->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'notes'       => $validated['notes'] ?? $purchaseOrder->notes,
        ]);

        ActivityLogService::log('approved', $purchaseOrder, [
            'approved_by' => $user->id,
            'notes'       => $validated['notes'] ?? null,
        ], "PO {$purchaseOrder->po_number} approved by {$user->first_name} {$user->last_name}");

        NotificationService::purchaseOrderStatusChanged(
            $purchaseOrder->id,
            $purchaseOrder->po_number,
            'pending_approval',
            'approved',
            $purchaseOrder->created_by
        );

        return response()->json([
            'message'        => "Purchase order {$purchaseOrder->po_number} approved.",
            'purchase_order' => $purchaseOrder->fresh(['approvedBy:id,first_name,last_name']),
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

        $user = $request->user();
        if (!$user->can('procurement.approve')) {
            return response()->json(['message' => 'You do not have permission to reject purchase orders.'], 403);
        }

        $purchaseOrder->update([
            'status'      => 'draft',           // Return to draft so it can be revised
            'approved_by' => null,
            'approved_at' => null,
            'notes'       => "REJECTED: {$validated['reason']}\n\n" . ($purchaseOrder->notes ?? ''),
        ]);

        ActivityLogService::log('rejected', $purchaseOrder, [
            'rejected_by' => $user->id,
            'reason'      => $validated['reason'],
        ], "PO {$purchaseOrder->po_number} rejected by {$user->first_name} {$user->last_name}: {$validated['reason']}");

        NotificationService::purchaseOrderStatusChanged(
            $purchaseOrder->id,
            $purchaseOrder->po_number,
            'pending_approval',
            'draft',
            $purchaseOrder->created_by
        );

        return response()->json([
            'message'        => "Purchase order {$purchaseOrder->po_number} rejected and returned to draft.",
            'purchase_order' => $purchaseOrder->fresh(),
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

        $purchaseOrder->update([
            'status' => 'cancelled',
            'notes'  => "CANCELLED: {$validated['reason']}\n\n" . ($purchaseOrder->notes ?? ''),
        ]);

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

        $return->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'notes'       => $validated['notes'] ?? $return->notes,
        ]);

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

        $return->update([
            'status' => 'rejected',
            'notes'  => "REJECTED: {$validated['reason']}\n\n" . ($return->notes ?? ''),
        ]);

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
            return response()->json([
                'message' => 'Failed to delete purchase order.',
                'error'   => $e->getMessage(),
            ], 500);
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
    // Private helpers
    // =========================================================================

    private function receiveProductInventory(
        PurchaseOrderItem $poItem,
        float $qty,
        array $validated,
        PurchaseOrder $purchaseOrder,
        string $grnNumber,
        int $userId
    ): void {
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

            // Also propagate to every existing outlet-specific row so that each
            // POS terminal immediately sees updated stock without requiring a
            // separate stock transfer step.
            $outletRows = InventoryItem::where('product_id', $poItem->product_id)
                ->when($poItem->product_variant_id,
                    fn ($q) => $q->where('product_variant_id', $poItem->product_variant_id),
                    fn ($q) => $q->whereNull('product_variant_id')
                )
                ->whereNotNull('outlet_id')
                ->get();

            foreach ($outletRows as $outletItem) {
                // Increment without logging a separate transaction (the warehouse
                // transaction is the authoritative record).
                $outletItem->quantity_on_hand += $qtyInt;
                $outletItem->save();
            }
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
                fn ($q) => $q  // update all if no specific outlet
            )
            ->increment('quantity_on_hand', $qtyInt);

        Inventory::where('inventory_type', 'product')
            ->where('product_id', $poItem->product_id)
            ->when($poItem->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $poItem->product_variant_id)
            )
            ->update(['cost_per_unit' => $poItem->unit_price, 'updated_at' => now()]);
    }

    private function receiveMaterialInventory(
        PurchaseOrderItem $poItem,
        float $qty,
        array $validated,
        PurchaseOrder $purchaseOrder,
        string $grnNumber,
        int $userId
    ): void {
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

        // Update unit cost on the material itself
        if ($poItem->unit_price > 0) {
            Material::where('id', $poItem->material_id)
                ->update(['unit_cost' => $poItem->unit_price]);
        }
    }
}