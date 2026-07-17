<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillOfMaterial;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\MaterialInventory;
use App\Models\Product;
use App\Models\Channel;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductionAutoAssigneeRule;
use App\Models\ProductionOrderApproval;
use App\Models\ProductionOrderAssignee;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\IntelligenceService;
use App\Services\ProductSerialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    // =========================================================================
    // GET /admin/production-orders
    // =========================================================================

    public function index(Request $request)
    {
        $query = ProductionOrder::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'variant:id,variant_name,sku',
            'tasks.stage',
            'tasks.assignedTo:id,first_name,last_name',
            'createdBy:id,first_name,last_name',
            'customerOrder:id,order_number,customer_first_name,customer_last_name,customer_phone,customer_email',
        ]);

        if ($request->filled('status')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $request->status))));
            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        }
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('outlet_id')) $query->where('outlet_id', $request->outlet_id);
        if ($request->filled('product_id')) $query->where('product_id', $request->product_id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) =>
                $q->where('order_number', 'ILIKE', "%{$s}%")
                  ->orWhereHas('product.translations', fn ($qt) =>
                      $qt->where('name', 'ILIKE', "%{$s}%")
                  )
            );
        }

        if ($request->filled('due_before')) $query->whereDate('due_date', '<=', $request->due_before);
        if ($request->filled('overdue'))     $query->where('due_date', '<', now())->whereNotIn('status', ['completed', 'cancelled']);

        $sortBy = in_array($request->get('sort_by'), ['due_date', 'priority', 'created_at', 'status'])
            ? $request->get('sort_by') : 'created_at';
        $query->orderBy($sortBy, $request->get('sort_order', 'desc'));

        // Stats alongside list
        $stats = [
            'draft'       => ProductionOrder::where('status', 'draft')->count(),
            'pending'     => ProductionOrder::where('status', 'pending')->count(),
            'in_progress' => ProductionOrder::where('status', 'in_progress')->count(),
            'qc_pending'  => ProductionOrder::where('status', 'qc_pending')->count(),
            'completed'   => ProductionOrder::where('status', 'completed')->count(),
            'overdue'     => ProductionOrder::where('due_date', '<', now())
                                ->whereNotIn('status', ['completed', 'cancelled', 'draft'])->count(),
        ];

        $orders = $query->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data'  => $this->transformList($orders->items()),
            'meta'  => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                // FIX: from/to were missing - the frontend pagination footer
                // ("Showing {from}-{to} of {total}") needs these, same as every
                // other paginated list page (StockTransfersPage, PurchaseOrdersPage,
                // PurchaseReturnsPage, StockAdjustmentsPage, etc). Without them
                // the footer rendered "Showing undefined-undefined of N".
                'from'         => $orders->firstItem(),
                'to'           => $orders->lastItem(),
                'total'        => $orders->total(),
            ],
            'stats' => $stats,
        ]);
    }

    // =========================================================================
    // GET /admin/production-orders/{id}
    // =========================================================================

    public function show($id)
    {
        $order = ProductionOrder::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product.images' => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'variant:id,variant_name,sku',
            'customerOrder:id,order_number,customer_first_name,customer_last_name,customer_phone,customer_email',
            'outlet:id,name',
            'tasks.stage:id,name,slug,sort_order',
            'tasks.assignedTo:id,first_name,last_name',
            'materialAllocations.material:id,name,code,unit_of_measure',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        // Active BOM for this product
        $bom = BillOfMaterial::with(['items.material.inventory'])
            ->where('product_id', $order->product_id)
            ->where('is_active', true)
            ->first();

        // Material availability check
        $materialRequirements = $this->calculateMaterialRequirements($bom, $order->quantity);

        $data = $order->toArray();
        $data['product_name'] = $order->product->translations->first()?->name ?? $order->product->sku;
        $data['product_image'] = $order->product->images->first()?->image_url;
        $data['bom']  = $bom?->toArray();
        $data['material_requirements'] = $materialRequirements;
        $data['completion_percentage'] = $this->calcCompletion($order);
        $data['current_stage'] = $this->getCurrentStage($order);

        return response()->json(['order' => $data]);
    }

    // =========================================================================
    // POST /admin/production-orders
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id'           => 'required|exists:products,id',
            'product_variant_id'   => 'nullable|exists:product_variants,id',
            'quantity'             => 'required|integer|min:1',
            'priority'             => 'required|in:low,normal,high,urgent',
            'due_date'             => 'required|date|after:today',
            'customer_order_id'    => 'nullable|exists:orders,id',
            'order_item_id'        => 'nullable|exists:order_items,id',
            'outlet_id'            => 'nullable|exists:outlets,id',
            'specifications'       => 'nullable|array',
            'measurements'         => 'nullable|array',
            'customer_preferences' => 'nullable|array',
            'notes'                => 'nullable|string|max:2000',
            'is_customer_order'    => 'nullable|boolean',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        // Get active BOM
        $bom = BillOfMaterial::with('items.material.inventory')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->first();

        // BOM is optional for draft orders - can be defined before confirming
        $bomWarnings = [];
        $requirements = [];
        $shortages    = [];
        if ($bom) {
            $requirements = $this->calculateMaterialRequirements($bom, $validated['quantity']);
            $shortages    = array_filter($requirements, fn ($r) => $r['is_short']);
        } else {
            $bomWarnings[] = 'No active Bill of Materials found. Please define a BOM before starting production.';
        }

        DB::beginTransaction();
        try {
            $order = ProductionOrder::create([
                'product_id'           => $validated['product_id'],
                'product_variant_id'   => $validated['product_variant_id'] ?? null,
                'quantity'             => $validated['quantity'],
                'priority'             => $validated['priority'],
                'due_date'             => $validated['due_date'],
                'customer_order_id'    => $validated['customer_order_id'] ?? null,
                'order_item_id'        => $validated['order_item_id'] ?? null,
                'outlet_id'            => $validated['outlet_id'] ?? null,
                'specifications'       => $validated['specifications'] ?? null,
                'measurements'         => $validated['measurements'] ?? null,
                'customer_preferences' => $validated['customer_preferences'] ?? null,
                'notes'                => $validated['notes'] ?? null,
                'status'               => 'draft',
                // is_customer_order is true when customer_order_id is present OR explicitly set
                'is_customer_order'    => $validated['is_customer_order']
                                            ?? (isset($validated['customer_order_id']) && $validated['customer_order_id'] !== null),
                'created_by'           => $request->user()->id,
            ]);

            // Tasks are created on confirm (draft -> pending). Don't pollute the production
            // floor board with unconfirmed/unpaid orders.
            if ($order->status !== 'draft') {
                // Stages come from the product's template (all active stages when
                // the product has none) — see ProductionOrder::seedTasks.
                $order->seedTasks();
            }

            // Pre-allocate materials from BOM (guarded - BOM is optional)
            if ($bom && $bom->items) {
                foreach ($bom->items as $bomItem) {
                    $required = round($bomItem->quantity * $validated['quantity'], 4);
                    MaterialAllocation::create([
                        'production_order_id' => $order->id,
                        'material_id'         => $bomItem->material_id,
                        'quantity_required'   => $required,
                        'quantity_allocated'  => 0,
                        'quantity_used'       => 0,
                        'quantity_returned'   => 0,
                    ]);
                }
            }

            DB::commit();

            // ── Audit + Notification ──────────────────────────────────────────
            $productName = $product->translations?->first()?->name ?? $product->sku ?? "Product #{$product->id}";
            ActivityLogService::log('created', $order, [
                'product_name'  => $productName,
                'quantity'      => $validated['quantity'],
                'priority'      => $validated['priority'],
                'due_date'      => $validated['due_date'],
                'has_bom'       => $bom !== null,
            ], "Created production order {$order->order_number} for {$validated['quantity']}x {$productName}");

            NotificationService::productionOrderCreated(
                $order->id,
                $order->order_number,
                $productName,
                $validated['quantity']
            );

            return response()->json([
                'message'  => 'Production order created successfully.',
                'order'    => $order->load(['tasks.stage', 'materialAllocations.material']),
                'warnings' => array_merge(
                    $bomWarnings,
                    !empty($shortages) ? ['Some materials are insufficient. Review and allocate before starting.'] : []
                ),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create production order.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PUT /admin/production-orders/{id}
    // =========================================================================

    public function update(Request $request, $id)
    {
        $order = ProductionOrder::findOrFail($id);

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot edit a completed or cancelled production order.'], 422);
        }

        $validated = $request->validate([
            'quantity'                  => 'sometimes|integer|min:1',
            'priority'                  => 'sometimes|in:low,normal,high,urgent',
            'due_date'                  => 'sometimes|date',
            'estimated_completion_date' => 'nullable|date',
            'specifications'            => 'nullable|array',
            'measurements'              => 'nullable|array',
            'customer_preferences'      => 'nullable|array',
            'notes'                     => 'nullable|string|max:2000',
            'target_outlet_id'          => 'nullable|exists:outlets,id',
        ]);

        $order->update($validated);

        try {
            ActivityLogService::log('production_order_updated', $order, [
                'order_number' => $order->order_number,
                'changes'      => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Production order updated.',
            'order'   => $order->fresh(),
        ]);
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/assign
    // Assign tailor(s) to tasks
    // =========================================================================

    public function assign(Request $request, $id)
    {
        $validated = $request->validate([
            'assignments'                  => 'required|array|min:1',
            'assignments.*.task_id'        => 'required|exists:production_tasks,id',
            'assignments.*.tailor_id'      => 'required|exists:users,id',
            'assignments.*.estimated_hours'=> 'nullable|numeric|min:0',
        ]);

        $order = ProductionOrder::with('tasks')->findOrFail($id);

        DB::beginTransaction();
        try {
            foreach ($validated['assignments'] as $a) {
                $task = $order->tasks->firstWhere('id', $a['task_id']);
                if (!$task) continue;

                $task->update([
                    'assigned_to'     => $a['tailor_id'],
                    'estimated_hours' => $a['estimated_hours'] ?? $task->estimated_hours,
                    'status'          => 'pending',
                ]);
            }

            if ($order->status === 'pending') {
                $order->update(['status' => 'in_progress']);
            }

            DB::commit();

            // Phase 3 - notify each assigned tailor
            try {
                $productName = $order->product?->translations->firstWhere('language_code', 'en')?->name
                    ?? $order->product?->sku ?? "Production #{$order->order_number}";
                $notifiedTailors = [];
                foreach ($validated['assignments'] as $a) {
                    if (!in_array($a['tailor_id'], $notifiedTailors)) {
                        NotificationService::productionAssigned(
                            $order->id,
                            $order->order_number,
                            $productName,
                            $a['tailor_id']
                        );
                        $notifiedTailors[] = $a['tailor_id'];
                    }
                }
                ActivityLogService::log('production_assigned', $order, [
                    'assignments' => collect($validated['assignments'])->map(fn ($a) => [
                        'task_id'   => $a['task_id'],
                        'tailor_id' => $a['tailor_id'],
                    ])->toArray(),
                ]);

                // ── Sync new assignees into the order's context channel ────────
                $orderChannel = Channel::where('context_type', 'production_order')
                    ->where('context_id', $order->id)
                    ->first();
                if ($orderChannel) {
                    $toAdd = collect($notifiedTailors)
                        ->unique()
                        ->mapWithKeys(fn ($uid) => [$uid => ['role' => 'member']])
                        ->toArray();
                    $orderChannel->members()->syncWithoutDetaching($toAdd);
                }

            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Tasks assigned successfully.',
                'order'    => $order->fresh(['tasks.stage', 'tasks.assignedTo:id,first_name,last_name']),
                'workload' => IntelligenceService::tailorWorkloadSnapshot(), // updated after assignment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Assignment failed.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PUT /admin/production-orders/{id}/stage
    // Start, complete, or fail a specific task/stage
    // =========================================================================

    public function updateStage(Request $request, $id)
    {
        $validated = $request->validate([
            'task_id'      => 'required|exists:production_tasks,id',
            'action'       => 'required|in:start,complete,fail,pause',
            'actual_hours' => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string|max:1000',
        ]);

        $order = ProductionOrder::with('tasks.stage')->findOrFail($id);
        $task  = $order->tasks->firstWhere('id', $validated['task_id']);

        if (!$task) {
            return response()->json(['message' => 'Task not found on this production order.'], 404);
        }

        DB::beginTransaction();
        try {
            switch ($validated['action']) {
                case 'start':
                    $task->start();
                    if ($order->started_at === null) {
                        $order->update(['status' => 'in_progress', 'started_at' => now()]);
                    }
                    break;

                case 'complete':
                    $task->complete($validated['actual_hours'] ?? null);
                    if ($validated['notes'] ?? null) $task->update(['notes' => $validated['notes']]);

                    // Check if all tasks done
                    $order->refresh();
                    $allDone = $order->tasks->every(fn ($t) => $t->status === 'completed');
                    if ($allDone) {
                        $order->update(['status' => 'qc_pending']);
                    }
                    break;

                case 'fail':
                    $task->update(['status' => 'failed', 'notes' => $validated['notes'] ?? null]);
                    $order->update(['status' => 'on_hold']);
                    break;

                case 'pause':
                    $task->update(['status' => 'paused']);
                    $order->update(['status' => 'on_hold']);
                    break;
            }

            DB::commit();

            // Phase 3 - notify on stage completion
            try {
                if ($validated['action'] === 'complete') {
                    $productName = $order->product?->translations->firstWhere('language_code', 'en')?->name
                        ?? $order->product?->sku ?? "Order #{$order->order_number}";
                    $stageName = $task->stage?->name ?? "Stage";
                    NotificationService::productionStageCompleted(
                        $order->id,
                        $order->order_number,
                        $stageName,
                        $productName
                    );
                    ActivityLogService::log('production_stage_completed', $order, [
                        'task_id'    => $task->id,
                        'stage_name' => $stageName,
                    ]);
                }
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Stage updated.',
                'order'   => $order->fresh(['tasks.stage', 'tasks.assignedTo:id,first_name,last_name']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update stage.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/materials
    // Allocate and issue materials to a production order
    // =========================================================================

    public function issueMaterials(Request $request, $id)
    {
        $validated = $request->validate([
            'allocations'                    => 'required|array|min:1',
            'allocations.*.allocation_id'    => 'required|exists:material_allocations,id',
            'allocations.*.quantity'         => 'required|numeric|min:0.001',
        ]);

        $order = ProductionOrder::findOrFail($id);

        DB::beginTransaction();
        try {
            foreach ($validated['allocations'] as $entry) {
                $allocation = MaterialAllocation::with('material.inventory')->findOrFail($entry['allocation_id']);

                if ($allocation->production_order_id !== $order->id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Allocation does not belong to this order.'], 422);
                }

                // Check material stock (from MaterialInventory)
                $stock = MaterialInventory::where('material_id', $allocation->material_id)->sum('quantity_on_hand');
                if ($stock < $entry['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'message'      => "Insufficient stock for {$allocation->material->name}. Available: {$stock} {$allocation->material->unit_of_measure}",
                        'material_id'  => $allocation->material_id,
                        'available'    => $stock,
                        'requested'    => $entry['quantity'],
                    ], 422);
                }

                // Deduct from material inventory
                MaterialInventory::where('material_id', $allocation->material_id)
                    ->orderBy('id')
                    ->first()
                    ?->decrement('quantity_on_hand', $entry['quantity']);

                // Update allocation
                $allocation->update([
                    'quantity_allocated' => $allocation->quantity_allocated + $entry['quantity'],
                    'allocated_at'       => $allocation->allocated_at ?? now(),
                    'allocated_by'       => $request->user()->id,
                ]);

                // Record material transaction using the actual schema
                $inventory = MaterialInventory::where('material_id', $allocation->material_id)
                    ->orderBy('id')->first();
                if ($inventory) {
                    $qtyBefore = (float) $inventory->quantity_on_hand;
                    $qtyAfter  = max(0, $qtyBefore - (float) $entry['quantity']);
                    DB::table('material_transactions')->insert([
                        'material_inventory_id' => $inventory->id,
                        'transaction_type'      => 'production_use',
                        'reference_type'        => 'production_order',
                        'reference_id'          => $order->id,
                        'quantity_change'       => -(float) $entry['quantity'],
                        'quantity_before'       => $qtyBefore,
                        'quantity_after'        => $qtyAfter,
                        'notes'                 => "Issued to production order {$order->order_number}",
                        'created_by'            => $request->user()->id,
                        'created_at'            => now(),
                    ]);
                }
            }

            DB::commit();

            try {
                ActivityLogService::log('production_materials_issued', $order, [
                    'order_number'    => $order->order_number,
                    'allocation_count'=> count($validated['allocations']),
                    'allocations'     => array_map(fn($a) => [
                        'allocation_id' => $a['allocation_id'],
                        'quantity'      => $a['quantity'],
                    ], $validated['allocations']),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Materials issued successfully.',
                'order'   => $order->fresh(['materialAllocations.material']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to issue materials.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/qc
    // Quality control check
    // =========================================================================

    public function qualityCheck(Request $request, $id)
    {
        $validated = $request->validate([
            'passed'          => 'required|boolean',
            'passed_quantity' => 'required|integer|min:0',
            'failed_quantity' => 'nullable|integer|min:0',
            'defect_types'    => 'nullable|array',
            'defect_types.*'  => 'string',
            'notes'           => 'nullable|string|max:2000',
            'images'          => 'nullable|array',
        ]);

        $order = ProductionOrder::findOrFail($id);

        if ($order->status !== 'qc_pending') {
            return response()->json(['message' => 'Order must be at QC pending stage.'], 422);
        }

        DB::beginTransaction();
        try {
            $newStatus = $validated['passed'] ? 'qc_passed' : 'qc_failed';

            $order->update([
                'status' => $newStatus,
                'notes'  => $order->notes
                    ? $order->notes . "\n\nQC: " . ($validated['notes'] ?? ($validated['passed'] ? 'Passed' : 'Failed'))
                    : "QC: " . ($validated['notes'] ?? ($validated['passed'] ? 'Passed' : 'Failed')),
            ]);

            // Record QC result
            DB::table('production_quality_checks')->insertOrIgnore([
                'production_order_id' => $order->id,
                'passed'              => $validated['passed'],
                'passed_quantity'     => $validated['passed_quantity'],
                'failed_quantity'     => $validated['failed_quantity'] ?? 0,
                'defect_types'        => json_encode($validated['defect_types'] ?? []),
                'notes'               => $validated['notes'] ?? null,
                'checked_by'          => $request->user()->id,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('production_quality_check', $order, [
                    'order_number'    => $order->order_number,
                    'passed'          => $validated['passed'],
                    'passed_quantity' => $validated['passed_quantity'],
                    'failed_quantity' => $validated['failed_quantity'] ?? 0,
                    'defect_types'    => $validated['defect_types'] ?? [],
                    'new_status'      => $newStatus,
                ]);
            } catch (\Exception) {}

            try {
                $productName = $order->product?->translations?->first()?->name
                    ?? $order->product?->sku
                    ?? "Product #{$order->product_id}";
                if ($validated['passed']) {
                    NotificationService::productionQcPassed(
                        $order->id,
                        $order->order_number,
                        $productName,
                        (int) $validated['passed_quantity']
                    );
                } else {
                    NotificationService::productionQcFailed(
                        $order->id,
                        $order->order_number,
                        $productName,
                        (int) ($validated['failed_quantity'] ?? 0),
                        $validated['notes'] ?? ''
                    );
                }
            } catch (\Exception) {}

            return response()->json([
                'message' => $validated['passed'] ? 'QC passed. Ready to complete.' : 'QC failed. Order placed on hold.',
                'order'   => $order->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to record QC.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/complete
    // Mark complete and move to finished goods inventory
    // =========================================================================

    public function complete(Request $request, $id)
    {
        $validated = $request->validate([
            'outlet_id'     => 'nullable|exists:outlets,id',
            'final_quantity'=> 'nullable|integer|min:1',
        ]);

        $order = ProductionOrder::with(['product', 'variant'])->findOrFail($id);

        if ($order->status !== 'qc_passed') {
            return response()->json(['message' => 'Order must pass quality check before completion.'], 422);
        }

        DB::beginTransaction();
        try {
            $qty       = $validated['final_quantity'] ?? $order->quantity;
            $variantId = $order->product_variant_id
                ?? $order->product->variants()->first()?->id;

            if (!$variantId) {
                DB::rollBack();
                return response()->json(['message' => 'No product variant found. Please add a variant to the product first.'], 422);
            }

            // Add to InventoryItem (finished goods)
            $inventoryItem = InventoryItem::firstOrCreate(
                [
                    'product_id'         => $order->product_id,
                    'product_variant_id' => $variantId,
                    'outlet_id'          => $validated['outlet_id'] ?? null,
                ],
                ['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0]
            );

            $inventoryItem->adjustQuantity(
                $qty,
                'production',
                ProductionOrder::class,
                $order->id,
                $request->user()->id
            );

            // Move this order's serials from in_production → in_stock (linked to the
            // finished-goods inventory item), reconciled to the produced quantity.
            ProductSerialService::stockFromProductionOrder(
                $order,
                $inventoryItem->id,
                $validated['outlet_id'] ?? null,
                $qty,
            );

            $order->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'quantity'     => $qty,
            ]);

            // Update linked order if any
            if ($order->customer_order_id) {
                DB::table('orders')
                    ->where('id', $order->customer_order_id)
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->update(['status' => 'processing']);
            }

            DB::commit();

            // Phase 3 - audit log on production completion
            try {
                $productName = $order->product?->translations?->first()?->name ?? $order->product?->sku ?? "Product #{$order->product_id}";
                ActivityLogService::log('production_completed', $order, [
                    'quantity'    => $qty,
                    'variant_id'  => $variantId,
                    'outlet_id'   => $validated['outlet_id'] ?? null,
                    'product_name'=> $productName,
                ], "Production order {$order->order_number} completed - {$qty}x {$productName} added to inventory");

                NotificationService::productionOrderCompleted(
                    $order->id,
                    $order->order_number,
                    $productName,
                    $qty
                );
            } catch (\Exception) {}

            return response()->json([
                'message' => "Production order {$order->order_number} completed. {$qty} unit(s) added to inventory.",
                'order'   => $order->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to complete production order.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/cancel
    // =========================================================================

    public function destroy(Request $request, $id)
    {
        $order = ProductionOrder::findOrFail($id);

        if (in_array($order->status, ['in_progress', 'qc_pending', 'qc_passed', 'completed'])) {
            return response()->json(['message' => 'Cannot cancel an order that is in progress or completed.'], 422);
        }

        $reason = $request->input('reason', '');

        $order->update([
            'status' => 'cancelled',
            'notes'  => $reason
                ? (($order->notes ? $order->notes . "\n\n" : '') . "Cancelled: {$reason}")
                : $order->notes,
        ]);

        // Void any serials that were reserved for this cancelled order.
        ProductSerialService::cancelForProductionOrder($order);

        try {
            ActivityLogService::log('production_order_cancelled', $order, [
                'order_number' => $order->order_number,
                'product_id'   => $order->product_id,
                'quantity'     => $order->quantity,
                'reason'       => $reason ?: null,
            ]);
        } catch (\Exception) {}

        try {
            // Notify any assigned users via the overdue channel (reuses existing assignee logic)
            $assignedIds = \App\Models\ProductionTask::where('production_order_id', $order->id)
                ->whereNotNull('assigned_to')
                ->pluck('assigned_to')
                ->unique()
                ->values()
                ->toArray();

            foreach ($assignedIds as $userId) {
                NotificationService::productionOverdue($order->id, $order->order_number, $userId);
            }
        } catch (\Exception) {}

        return response()->json(['message' => 'Production order cancelled.']);
    }

    // =========================================================================
    // GET /admin/production-orders/{id}/timeline
    // =========================================================================

    public function timeline($id)
    {
        $order = ProductionOrder::with([
            'tasks.stage:id,name,slug,sort_order',
            'tasks.assignedTo:id,first_name,last_name',
            'materialAllocations.material:id,name,code,unit_of_measure',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        $qcResults = DB::table('production_quality_checks')
            ->where('production_order_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'order'      => $order,
            'qc_results' => $qcResults,
        ]);
    }

    // =========================================================================
    // GET /admin/production-tasks  -- all tasks (admin view)
    // =========================================================================

    public function allTasks(Request $request)
    {
        $query = ProductionTask::with([
            'productionOrder:id,order_number,priority,due_date,status,quantity,product_id',
            'stage:id,name,slug',
            'assignedTo:id,first_name,last_name',
        ]);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('tailor_id'))  $query->where('assigned_to', $request->tailor_id);
        if ($request->filled('stage_id'))   $query->where('production_stage_id', $request->stage_id);

        return response()->json($query->orderByDesc('created_at')->paginate(50));
    }

    // =========================================================================
    // GET /tailor/tasks  -- tailor self-service
    // =========================================================================

    public function myTasks(Request $request)
    {
        $includeCompleted = filter_var($request->query('include_completed', false), FILTER_VALIDATE_BOOLEAN);

        $query = ProductionTask::with([
            'productionOrder:id,order_number,priority,due_date,status,quantity,product_id,specifications,notes,measurements,customer_preferences,customer_id',
            'productionOrder.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'productionOrder.product.images' => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'productionOrder.materialAllocations.material:id,name,unit_of_measure',
            'productionOrder.customer:id,first_name,last_name',
            'stage:id,name,slug',
        ])
        ->where('assigned_to', $request->user()->id);

        if ($includeCompleted) {
            // All tasks: active statuses first, then completed, then failed
            $query->orderByRaw("CASE
                WHEN status = 'in_progress' THEN 0
                WHEN status = 'pending'     THEN 1
                WHEN status = 'paused'      THEN 2
                WHEN status = 'completed'   THEN 3
                ELSE 4
            END");
        } else {
            // Active only — exclude completed/failed so Intelligence sort works on the right set
            $query->whereIn('status', ['pending', 'in_progress', 'paused']);
        }

        $tasks = $query->get();

        // ── Lock visibility ──────────────────────────────────────────────────
        // Stages are gated in sequence (see updateTaskStatus). The tailor should
        // SEE "waiting on Stitching" on the card, not discover it as an error
        // after tapping Start. One query for all unfinished predecessor tasks
        // across every order on this list — not a per-task lookup.
        $orderIds = $tasks->pluck('production_order_id')->unique()->values();
        $openSiblings = ProductionTask::whereIn('production_order_id', $orderIds)
            ->whereNotNull('sequence')
            ->whereNotIn('status', ProductionTask::SATISFIED_STATUSES)
            ->with('stage:id,name')
            ->get(['id', 'production_order_id', 'production_stage_id', 'sequence', 'status'])
            ->groupBy('production_order_id');

        $tasks->each(function ($task) use ($openSiblings) {
            $blocker = null;
            if (!$task->concurrent_allowed && $task->sequence !== null && !$task->started_at) {
                $blocker = ($openSiblings[$task->production_order_id] ?? collect())
                    ->filter(fn ($s) => $s->sequence !== null && $s->sequence < $task->sequence)
                    ->sortBy('sequence')
                    ->first();
            }
            $task->setAttribute('blocked_by_stage', $blocker?->stage?->name);
        });

        if ($includeCompleted) {
            // For the history view, keep the DB sort as-is (completed tasks are already at the bottom)
            return response()->json($tasks);
        }

        // Intelligence #9 — sort active tasks by deadline-miss risk score
        $sorted = IntelligenceService::smartTaskSort($tasks->toArray());

        return response()->json($sorted);
    }

    // =========================================================================
    // PUT /tailor/tasks/{id}/status
    // =========================================================================

    /**
     * Let one stage run in parallel with its predecessors.
     *
     * The default is strictly sequential. But embroidery can genuinely run while
     * stitching is still going — on different pieces that merge later — and that
     * call belongs to the manager running the floor, not to whichever tailor
     * wants to start early. Route-gated by production.manage_assignees (the
     * existing manager-of-production permission), and the grant is stamped onto
     * the task: who unlocked it, and when. Re-lock by passing allow=false — only
     * while the task has not started.
     */
    public function unlockTask(Request $request, $id)
    {
        $validated = $request->validate([
            'allow' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);
        $allow = $validated['allow'] ?? true;

        $task = ProductionTask::with(['stage:id,name', 'productionOrder:id,order_number'])->findOrFail($id);

        if (!$allow && $task->started_at) {
            return response()->json(['message' => 'This stage has already started; it can no longer be re-locked.'], 422);
        }

        $task->update([
            'concurrent_allowed' => $allow,
            'unlocked_by'        => $allow ? $request->user()->id : null,
            'unlocked_at'        => $allow ? now() : null,
        ]);

        try {
            ActivityLogService::log(
                $allow ? 'production_stage_unlocked' : 'production_stage_relocked',
                $task->productionOrder,
                [
                    'task_id' => $task->id,
                    'stage'   => $task->stage?->name,
                    'notes'   => $validated['notes'] ?? null,
                ]
            );
        } catch (\Exception) {}

        return response()->json([
            'message' => $allow
                ? 'Stage unlocked - it may now run in parallel with earlier stages.'
                : 'Stage re-locked to sequential order.',
            'task' => $task->fresh(['stage']),
        ]);
    }

    public function updateTaskStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'action'       => 'required|in:start,complete,pause',
            'actual_hours' => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $task = ProductionTask::with('productionOrder')->findOrFail($id);

        if ($task->assigned_to !== $request->user()->id
            && !$request->user()->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json(['message' => 'You are not assigned to this task.'], 403);
        }

        // ── Stage gate ───────────────────────────────────────────────────────
        // Stages run in sequence: you cannot start (or complete a never-started)
        // task while an earlier stage is unfinished. The escape hatch is explicit:
        // a production manager marks the task concurrent_allowed (see unlockTask),
        // and that grant is recorded. There is deliberately no silent admin
        // bypass — the trail is the point.
        if (in_array($validated['action'], ['start', 'complete']) && !$task->started_at) {
            $blocker = $task->blockingTask();
            if ($blocker) {
                $blockerName = $blocker->stage?->name ?? 'an earlier stage';
                return response()->json([
                    'message' => "\"{$blockerName}\" must be completed before this stage can begin. "
                        . 'A production manager can unlock this stage to run them in parallel.',
                    'blocked_by' => [
                        'task_id' => $blocker->id,
                        'stage'   => $blockerName,
                        'status'  => $blocker->status,
                    ],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            switch ($validated['action']) {
                case 'start':
                    $task->start();
                    $order = $task->productionOrder;
                    if (!$order->started_at) {
                        $order->update(['status' => 'in_progress', 'started_at' => now()]);
                    }
                    break;

                case 'complete':
                    $task->complete($validated['actual_hours'] ?? null);
                    if ($validated['notes'] ?? null) $task->update(['notes' => $validated['notes']]);

                    // Check all tasks done
                    $allDone = ProductionTask::where('production_order_id', $task->production_order_id)
                        ->where('status', '!=', 'completed')
                        ->doesntExist();

                    if ($allDone) {
                        $task->productionOrder->update(['status' => 'qc_pending']);
                    }
                    break;

                case 'pause':
                    $task->update(['status' => 'paused']);
                    break;
            }

            DB::commit();

            try {
                ActivityLogService::log('production_task_status_updated', $task->productionOrder ?? null, [
                    'task_id'             => $task->id,
                    'production_order_id' => $task->production_order_id,
                    'action'              => $validated['action'],
                    'actual_hours'        => $validated['actual_hours'] ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Task updated.',
                'task'    => $task->fresh(['stage', 'productionOrder:id,order_number,status']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update task.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /tailor/tasks/{id}
    // =========================================================================

    public function taskDetails($id)
    {
        $task = ProductionTask::with([
            'productionOrder:id,order_number,priority,due_date,status,specifications,notes,quantity,product_id',
            'productionOrder.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'productionOrder.product.images' => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'productionOrder.materialAllocations.material',
            'productionOrder.tasks.stage:id,name,slug',
            'stage:id,name,slug,description',
        ])->findOrFail($id);

        return response()->json($task);
    }

    // =========================================================================
    // GET /tailor/tasks/{id}/history
    // Returns a merged timeline of structured timestamps from the task row
    // (assigned, started, completed) plus every activity_log entry that
    // references this task, ordered chronologically oldest→newest.
    // =========================================================================

    public function taskHistory(Request $request, $id)
    {
        $task = ProductionTask::with([
            'assignedTo:id,first_name,last_name',
            'productionOrder:id,order_number',
        ])->findOrFail($id);

        // Guard: tailor can only see their own task history
        if ($task->assigned_to !== $request->user()->id
            && !$request->user()->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $events = collect();

        // ── 1. Synthetic events from the task timestamps ──────────────────────
        $events->push([
            'id'         => 'task_created',
            'event'      => 'assigned',
            'label'      => 'Task Assigned',
            'actor_name' => null,
            'notes'      => null,
            'created_at' => $task->created_at?->toIso8601String(),
        ]);

        if ($task->started_at) {
            $events->push([
                'id'         => 'task_started',
                'event'      => 'started',
                'label'      => 'Task Started',
                'actor_name' => $task->assignedTo
                    ? trim("{$task->assignedTo->first_name} {$task->assignedTo->last_name}")
                    : null,
                'notes'      => null,
                'created_at' => $task->started_at->toIso8601String(),
            ]);
        }

        if ($task->status === 'completed' && $task->completed_at) {
            $events->push([
                'id'         => 'task_completed',
                'event'      => 'completed',
                'label'      => 'Task Completed',
                'actor_name' => $task->assignedTo
                    ? trim("{$task->assignedTo->first_name} {$task->assignedTo->last_name}")
                    : null,
                'notes'      => $task->notes,
                'created_at' => $task->completed_at->toIso8601String(),
            ]);
        }

        // ── 2. Activity log entries referencing this task ─────────────────────
        // The logger stores task_id inside the JSON `properties` column.
        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->whereRaw("al.properties::jsonb->>'task_id' = ?", [(string) $task->id])
            ->orderBy('al.created_at')
            ->select(
                'al.id',
                'al.event',
                'al.action',
                'al.properties',
                'al.created_at',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name")
            )
            ->get();

        $actionLabels = [
            'production_task_status_updated' => [
                'start'    => 'Task Started',
                'pause'    => 'Task Paused',
                'complete' => 'Task Completed',
            ],
        ];

        foreach ($logs as $log) {
            $props  = $log->properties ? json_decode($log->properties, true) : [];
            $event  = $log->event ?? $log->action ?? '';
            $action = $props['action'] ?? null;

            // Determine a human label
            $label = $actionLabels[$event][$action]
                ?? ucfirst(str_replace(['_', 'production task '], [' ', ''], $event));

            // Skip events whose timestamp is already represented by a synthetic entry
            // (started / completed) to avoid duplicates — activity log may be seconds apart
            $logTime = $log->created_at;
            $isDuplicate = false;
            if ($action === 'start'    && $task->started_at && abs(strtotime($logTime) - $task->started_at->timestamp) < 5)   $isDuplicate = true;
            if ($action === 'complete' && $task->completed_at && abs(strtotime($logTime) - $task->completed_at->timestamp) < 5) $isDuplicate = true;
            if ($isDuplicate) continue;

            $notes = null;
            if (!empty($props['actual_hours'])) {
                $notes = "Logged {$props['actual_hours']}h";
            }

            $events->push([
                'id'         => 'log_' . $log->id,
                'event'      => $event . ($action ? "_{$action}" : ''),
                'label'      => $label,
                'actor_name' => $log->actor_name,
                'notes'      => $notes,
                'created_at' => $logTime,
            ]);
        }

        // Sort chronologically (oldest first) and re-index
        $sorted = $events
            ->filter(fn ($e) => !empty($e['created_at']))
            ->sortBy('created_at')
            ->values();

        return response()->json(['history' => $sorted]);
    }

    // =========================================================================
    // POST /tailor/tasks/{id}/note  /  /admin/production-orders/{id}/note
    // =========================================================================

    public function addTaskNote(Request $request, $id)
    {
        $validated = $request->validate(['note' => 'required|string|max:1000']);

        ProductionOrder::findOrFail($id);

        DB::table('production_notes')->insertOrIgnore([
            'production_order_id' => $id,
            'user_id'             => $request->user()->id,
            'note'                => $validated['note'],
            'created_at'          => now(),
        ]);

        try {
            ActivityLogService::log('production_note_added', null, [
                'production_order_id' => $id,
                'note_preview'        => mb_substr($validated['note'], 0, 80),
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Note added.']);
    }

    // =========================================================================
    // Material allocations
    // =========================================================================

    public function allocations(Request $request)
    {
        $q = MaterialAllocation::with(['productionOrder:id,order_number', 'material:id,name,code,unit_of_measure']);
        if ($request->filled('production_order_id')) $q->where('production_order_id', $request->production_order_id);
        return response()->json($q->paginate(50));
    }

    public function allocateMaterials(Request $request, $id)
    {
        return $this->issueMaterials($request, $id);
    }

    public function updateAllocation(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity_required'  => 'sometimes|numeric|min:0',
            'quantity_allocated' => 'sometimes|numeric|min:0',
        ]);
        $allocation = MaterialAllocation::findOrFail($id);
        $allocation->update($validated);
        return response()->json(['message' => 'Allocation updated.', 'allocation' => $allocation->fresh()]);
    }

    public function deleteAllocation($id)
    {
        MaterialAllocation::findOrFail($id)->delete();
        return response()->json(['message' => 'Allocation removed.']);
    }

    // =========================================================================
    // Helper: material requirements from BOM
    // =========================================================================

    private function calculateMaterialRequirements(?BillOfMaterial $bom, int $quantity): array
    {
        if (!$bom) return [];

        return $bom->items->map(function ($item) use ($quantity) {
            $required  = round($item->quantity * $quantity, 4);
            $available = MaterialInventory::where('material_id', $item->material_id)->sum('quantity_on_hand');
            return [
                'material_id'   => $item->material_id,
                'material_name' => $item->material->name,
                'material_code' => $item->material->code,
                'unit'          => $item->unit_of_measure ?? $item->material->unit_of_measure,
                'required'      => $required,
                'available'     => (float) $available,
                'is_short'      => $available < $required,
                'shortage'      => max(0, $required - $available),
            ];
        })->toArray();
    }

    private function calcCompletion(ProductionOrder $order): int
    {
        $tasks = $order->tasks ?? collect();
        if ($tasks->isEmpty()) return 0;
        return (int) round($tasks->where('status', 'completed')->count() / $tasks->count() * 100);
    }

    private function getCurrentStage(ProductionOrder $order): ?string
    {
        return $order->tasks
            ?->firstWhere('status', 'in_progress')
            ?->stage?->name
            ?? $order->tasks?->firstWhere('status', 'pending')?->stage?->name;
    }

    private function transformList(array $items): array
    {
        return array_map(function ($o) {
            $data = $o->toArray();
            $data['product_name'] = $o->product->translations->first()?->name ?? $o->product->sku;
            $data['completion_percentage'] = $this->calcCompletion($o);
            $data['current_stage'] = $this->getCurrentStage($o);
            return $data;
        }, $items);
    }

    // =========================================================================
    // Production task details / reassign (admin)
    // =========================================================================

    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'production_order_id'  => 'required|exists:production_orders,id',
            'production_stage_id'  => 'required|exists:production_stages,id',
            'assigned_to'          => 'nullable|exists:users,id',
            'estimated_hours'      => 'nullable|numeric|min:0',
            'notes'                => 'nullable|string',
        ]);

        $task = ProductionTask::create($validated + ['status' => 'pending']);
        return response()->json(['task' => $task->load(['stage', 'assignedTo:id,first_name,last_name'])], 201);
    }

    public function updateTaskDetails(Request $request, $id)
    {
        $task = ProductionTask::findOrFail($id);
        $task->update($request->validate([
            'assigned_to'     => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
        ]));
        return response()->json(['task' => $task->fresh(['stage', 'assignedTo:id,first_name,last_name'])]);
    }

    public function deleteTask($id)
    {
        $task = ProductionTask::findOrFail($id);
        if ($task->status !== 'pending') return response()->json(['message' => 'Only pending tasks can be deleted.'], 422);
        $task->delete();
        return response()->json(['message' => 'Task deleted.']);
    }

    public function reassignTask(Request $request, $id)
    {
        $task = ProductionTask::findOrFail($id);
        $previousAssignee = $task->assigned_to;
        $newAssignee = $request->validate(['tailor_id' => 'required|exists:users,id'])['tailor_id'];
        $task->update(['assigned_to' => $newAssignee]);

        try {
            ActivityLogService::log('production_task_reassigned', null, [
                'task_id'              => $task->id,
                'production_order_id'  => $task->production_order_id,
                'previous_assignee_id' => $previousAssignee,
                'new_assignee_id'      => $newAssignee,
            ]);
        } catch (\Exception) {}

        return response()->json(['task' => $task->fresh(['assignedTo:id,first_name,last_name'])]);
    }
    // =========================================================================
    // POST /admin/production-orders/{id}/confirm
    //
    // Moves a draft order into the production queue (draft → pending).
    // Creates tasks from active stages, applies auto-assignee rules,
    // sends notifications, and records the payment_received gate approval.
    // For in-house stock orders, payment is not required - managers can
    // call this directly.
    // =========================================================================

    public function confirm(Request $request, $id)
    {
        $order = ProductionOrder::with(['product', 'outlet'])->findOrFail($id);

        if ($order->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be confirmed.'], 422);
        }

        // ── Phase 4: Payment Approval Gate ────────────────────────────────────
        // For production orders raised from a customer order, check whether all
        // payments on the linked sales order have been approved.  International
        // orders (USD) may have manual payments (bank_transfer / other) that sit
        // in pending_review until an admin approves them.  We must not start
        // production for those orders until at least the deposit is cleared.
        if ($order->is_customer_order && $order->customer_order_id) {
            $hasPendingApproval = DB::table('payments')
                ->where('order_id', $order->customer_order_id)
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->exists();

            if ($hasPendingApproval) {
                // Allow admins / super_admins to force-override with ?force=1
                $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
                if (!$force) {
                    // Return rich error so the frontend can show an actionable message
                    $pendingPayments = DB::table('payments')
                        ->where('order_id', $order->customer_order_id)
                        ->where('requires_approval', true)
                        ->where('approval_status', 'pending_review')
                        ->select('id', 'payment_number', 'amount', 'currency_code', 'payment_method')
                        ->get();

                    return response()->json([
                        'message'          => 'Cannot confirm production - the linked customer order has international payments awaiting admin approval.',
                        'reason'           => 'pending_payment_approval',
                        'pending_payments' => $pendingPayments,
                        'tip'              => 'Approve the payments in the Approvals queue, or pass ?force=1 to override (admin only).',
                    ], 422);
                }
            }

            // Also guard: if the linked order has no paid payments at all AND it is
            // an international order, refuse unless forced.
            $linkedOrder = DB::table('orders')
                ->where('id', $order->customer_order_id)
                ->select('currency_code', 'total_amount')
                ->first();

            if ($linkedOrder && $linkedOrder->currency_code === 'USD') {
                $totalPaid = DB::table('payments')
                    ->where('order_id', $order->customer_order_id)
                    ->where('status', 'paid')
                    ->sum('amount');

                if ($totalPaid <= 0) {
                    $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
                    if (!$force) {
                        return response()->json([
                            'message' => 'Cannot confirm production - no confirmed payment exists on this international order yet.',
                            'reason'  => 'no_payment_received',
                            'tip'     => 'Record and approve a payment first, or pass ?force=1 to override (admin only).',
                        ], 422);
                    }
                }
            }
        }
        // ── End gate ──────────────────────────────────────────────────────────

        DB::beginTransaction();
        try {
            // Transition to pending
            $order->update([
                'status'       => 'pending',
                'confirmed_at' => now(),
                'confirmed_by' => $request->user()->id,
            ]);

            // Approved for production → assign a unique serial to every unit so it
            // can be tracked from here through stock, sale and dispatch.
            ProductSerialService::generateForProductionOrder($order);

            // Create production tasks from the product's stage template
            $order->seedTasks();

            // Apply auto-assignee rules
            $this->applyAutoAssignees($order);

            // Record gate approval
            DB::table('production_order_approvals')->insert([
                'production_order_id' => $order->id,
                'gate'                => $order->is_customer_order ? 'payment_received' : 'stock_order_confirmed',
                'approved_by'         => $request->user()->id,
                'notes'               => $request->input('notes'),
                'approved_at'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            // Pre-allocate materials if BOM exists (don't block if missing)
            $bom = \App\Models\BillOfMaterial::with('items')
                ->where('product_id', $order->product_id)
                ->where('is_active', true)
                ->first();

            if ($bom) {
                foreach ($bom->items as $bomItem) {
                    $required = round($bomItem->quantity * $order->quantity, 4);
                    MaterialAllocation::firstOrCreate(
                        ['production_order_id' => $order->id, 'material_id' => $bomItem->material_id],
                        [
                            'quantity_required'  => $required,
                            'quantity_allocated' => 0,
                            'quantity_used'      => 0,
                            'quantity_returned'  => 0,
                        ]
                    );
                }
            }

            DB::commit();

            // Phase 3/4 - notify all assignees and audit
            try {
                $productName = $order->product?->translations
                    ->firstWhere('language_code', 'en')?->name
                    ?? $order->product?->sku
                    ?? "Order #{$order->order_number}";

                // Notify each uniquely assigned tailor
                $notifiedTailors = [];
                foreach ($order->fresh(['tasks'])->tasks ?? [] as $task) {
                    if ($task->assigned_to && !in_array($task->assigned_to, $notifiedTailors)) {
                        NotificationService::productionAssigned(
                            $order->id,
                            $order->order_number,
                            $productName,
                            $task->assigned_to
                        );
                        $notifiedTailors[] = $task->assigned_to;
                    }
                }

                ActivityLogService::log('production_confirmed', $order, [
                    'gate'         => $order->is_customer_order ? 'payment_received' : 'stock_order_confirmed',
                    'confirmed_by' => $request->user()->id,
                    'product'      => $productName,
                ]);

                // ── Create (or find) the context channel for this order ────────
                $channel = Channel::findOrCreateContext(
                    'production_order',
                    $order->id,
                    "PRD · {$order->order_number}",
                    $request->user()->id
                );

                // Add confirmer + all assigned tailors as channel members
                $memberIds = collect([$request->user()->id]);
                foreach ($notifiedTailors as $tailorId) {
                    $memberIds->push($tailorId);
                }
                $toAdd = $memberIds->unique()->mapWithKeys(fn ($uid) => [$uid => ['role' => 'member']])->toArray();
                $channel->members()->syncWithoutDetaching($toAdd);

                // Seed the thread with a system message
                \App\Models\ChannelMessage::create([
                    'channel_id' => $channel->id,
                    'user_id'    => null,
                    'type'       => 'system',
                    'body'       => "Production order {$order->order_number} confirmed. {$productName} · Qty {$order->quantity}.",
                ]);

            } catch (\Exception) {}

            return response()->json([
                'message' => 'Production order confirmed and queued.',
                'order'   => $order->fresh(['tasks.stage', 'assignees.user']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to confirm order.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /admin/production/schedule
    //
    // Returns production schedule visibility data used by the sales team
    // when raising a new order - so they can give customers a realistic ETA.
    // =========================================================================

    public function schedule()
    {
        $active = ProductionOrder::whereIn('status', ['pending', 'in_progress', 'on_hold', 'qc_pending'])
            ->with('tasks.stage')
            ->orderBy('due_date')
            ->get();

        $byStage = $active
            ->flatMap(fn ($o) => $o->tasks)
            ->whereIn('status', ['pending', 'in_progress'])
            ->groupBy('production_stage_id')
            ->map(fn ($tasks) => $tasks->count());

        $upcoming = $active
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays(14))
            ->map(fn ($o) => [
                'order_number'              => $o->order_number,
                'due_date'                  => $o->due_date,
                'estimated_completion_date' => $o->estimated_completion_date,
                'status'                    => $o->status,
                'priority'                  => $o->priority,
            ]);

        $earliestFree = $active->max('due_date')
            ? \Carbon\Carbon::parse($active->max('due_date'))->addDays(3)->toDateString()
            : now()->addDays(7)->toDateString();

        return response()->json([
            'active_count'   => $active->count(),
            'by_stage'       => $byStage,
            'upcoming_orders'=> $upcoming->values(),
            'earliest_free_slot' => $earliestFree,
        ]);
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/assignees
    // Add an assignee to a production order
    // =========================================================================

    public function addAssignee(Request $request, $id)
    {
        $validated = $request->validate([
            'user_id'      => 'required|exists:users,id',
            'role_in_order'=> 'nullable|string|max:100',
        ]);

        $order = ProductionOrder::findOrFail($id);

        $assignee = ProductionOrderAssignee::firstOrCreate(
            ['production_order_id' => $order->id, 'user_id' => $validated['user_id']],
            ['role_in_order' => $validated['role_in_order'] ?? 'assignee', 'auto_assigned' => false]
        );

        return response()->json([
            'message'  => 'Assignee added.',
            'assignee' => $assignee->load('user:id,first_name,last_name,email'),
        ], 201);
    }

    // =========================================================================
    // DELETE /admin/production-orders/{orderId}/assignees/{userId}
    // =========================================================================

    public function removeAssignee($orderId, $userId)
    {
        ProductionOrderAssignee::where('production_order_id', $orderId)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['message' => 'Assignee removed.']);
    }

    // =========================================================================
    // GET /admin/production-orders/{id}/assignees
    // =========================================================================

    public function assignees($id)
    {
        ProductionOrder::findOrFail($id);

        $assignees = ProductionOrderAssignee::with('user:id,first_name,last_name,email')
            ->where('production_order_id', $id)
            ->get();

        return response()->json($assignees);
    }

    // =========================================================================
    // POST /admin/production-orders/{id}/approvals
    // Record a gate approval sign-off
    // =========================================================================

    public function recordApproval(Request $request, $id)
    {
        $validated = $request->validate([
            'gate'  => 'required|in:payment_received,stock_order_confirmed,production_started,qc_passed,qc_failed,dispatched,delivered',
            'notes' => 'nullable|string|max:1000',
        ]);

        ProductionOrder::findOrFail($id);

        $approval = DB::table('production_order_approvals')->insertGetId([
            'production_order_id' => $id,
            'gate'                => $validated['gate'],
            'approved_by'         => $request->user()->id,
            'notes'               => $validated['notes'] ?? null,
            'approved_at'         => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        try {
            ActivityLogService::log('production_gate_approved', null, [
                'production_order_id' => $id,
                'gate'                => $validated['gate'],
                'approval_id'         => $approval,
                'notes'               => $validated['notes'] ?? null,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Gate approval recorded.',
            'approval' => DB::table('production_order_approvals')->find($approval),
        ], 201);
    }

    // =========================================================================
    // GET /admin/production-orders/{id}/approvals
    // =========================================================================

    public function approvalHistory($id)
    {
        ProductionOrder::findOrFail($id);

        $approvals = DB::table('production_order_approvals as a')
            ->join('users as u', 'a.approved_by', '=', 'u.id')
            ->where('a.production_order_id', $id)
            ->orderBy('a.approved_at')
            ->select('a.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as approver_name"))
            ->get();

        return response()->json($approvals);
    }

    // =========================================================================
    // Auto-assignee rules CRUD
    //  GET    /admin/production/auto-assignees
    //  POST   /admin/production/auto-assignees
    //  DELETE /admin/production/auto-assignees/{id}
    // =========================================================================

    public function autoAssignees()
    {
        return response()->json(
            ProductionAutoAssigneeRule::with('user:id,first_name,last_name,email', 'outlet:id,name')
                ->orderBy('id')
                ->get()
        );
    }

    public function createAutoAssignee(Request $request)
    {
        $validated = $request->validate([
            'user_id'      => 'required|exists:users,id',
            'role_in_order'=> 'nullable|string|max:100',
            'outlet_id'    => 'nullable|exists:outlets,id',
        ]);

        $rule = ProductionAutoAssigneeRule::create([
            'user_id'      => $validated['user_id'],
            'role_in_order'=> $validated['role_in_order'] ?? 'observer',
            'outlet_id'    => $validated['outlet_id'] ?? null,
            'is_active'    => true,
        ]);

        return response()->json([
            'message' => 'Auto-assignee rule created.',
            'rule'    => $rule->load('user:id,first_name,last_name', 'outlet:id,name'),
        ], 201);
    }

    public function deleteAutoAssignee($id)
    {
        ProductionAutoAssigneeRule::findOrFail($id)->delete();
        return response()->json(['message' => 'Auto-assignee rule deleted.']);
    }

    // =========================================================================
    // Private: apply auto-assignee rules to a newly confirmed order
    // =========================================================================

    private function applyAutoAssignees(ProductionOrder $order): void
    {
        $rules = ProductionAutoAssigneeRule::where('is_active', true)
            ->where(fn ($q) => $q
                ->whereNull('outlet_id')
                ->orWhere('outlet_id', $order->outlet_id)
            )
            ->get();

        foreach ($rules as $rule) {
            ProductionOrderAssignee::firstOrCreate(
                ['production_order_id' => $order->id, 'user_id' => $rule->user_id],
                ['role_in_order' => $rule->role_in_order, 'auto_assigned' => true]
            );
        }
    }

    // =========================================================================
    // GET  /admin/production-orders/{id}/messages
    // POST /admin/production-orders/{id}/messages
    // Activity log + chat for a production order
    // =========================================================================

    public function getMessages($id)
    {
        $order = ProductionOrder::findOrFail($id);

        $messages = DB::table('production_order_messages as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.production_order_id', $order->id)
            ->orderBy('m.created_at', 'asc')
            ->select([
                'm.id',
                'm.production_order_id',
                'm.type',
                'm.body',
                'm.created_at',
                'u.id as user_id',
                'u.first_name',
                'u.last_name',
            ])
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'type'       => $m->type,
                'body'       => $m->body,
                'created_at' => $m->created_at,
                'user'       => [
                    'id'         => $m->user_id,
                    'first_name' => $m->first_name,
                    'last_name'  => $m->last_name,
                    'initials'   => strtoupper(substr($m->first_name, 0, 1) . substr($m->last_name, 0, 1)),
                ],
            ]);

        return response()->json(['messages' => $messages]);
    }

    public function postMessage(Request $request, $id)
    {
        $order = ProductionOrder::findOrFail($id);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
            'type' => 'sometimes|in:message,note',
        ]);

        $messageId = DB::table('production_order_messages')->insertGetId([
            'production_order_id' => $order->id,
            'user_id'             => $request->user()->id,
            'type'                => $validated['type'] ?? 'message',
            'body'                => $validated['body'],
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $message = DB::table('production_order_messages as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.id', $messageId)
            ->select(['m.id', 'm.type', 'm.body', 'm.created_at',
                'u.id as user_id', 'u.first_name', 'u.last_name'])
            ->first();

        try {
            ActivityLogService::log('production_message_posted', $order, [
                'production_order_id' => $order->id,
                'order_number'        => $order->order_number,
                'message_id'          => $messageId,
                'type'                => $validated['type'] ?? 'message',
                'body_preview'        => mb_substr($validated['body'], 0, 80),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => [
                'id'         => $message->id,
                'type'       => $message->type,
                'body'       => $message->body,
                'created_at' => $message->created_at,
                'user'       => [
                    'id'         => $message->user_id,
                    'first_name' => $message->first_name,
                    'last_name'  => $message->last_name,
                    'initials'   => strtoupper(substr($message->first_name, 0, 1) . substr($message->last_name, 0, 1)),
                ],
            ],
        ], 201);
    }

    // =========================================================================
    // GET /admin/production-orders/{id}/audit-log
    // =========================================================================

    public function auditLog($id)
    {
        $order = ProductionOrder::findOrFail($id);

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where('al.subject_type', \App\Models\ProductionOrder::class)
            ->where('al.subject_id', $order->id)
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
                    'created'                     => 'Order Created',
                    'production_created'          => 'Order Created',
                    'approved'                    => 'Order Approved',
                    'production_confirmed'        => 'Order Confirmed',
                    'status_changed'              => 'Status Changed',
                    'production_assigned'         => 'Tailor Assigned',
                    'production_stage_completed'  => 'Stage Completed',
                    'production_completed'        => 'Order Completed',
                    'cancelled'                   => 'Order Cancelled',
                    'materials_allocated'         => 'Materials Allocated',
                    'qc_passed'                   => 'QC Passed',
                    'qc_failed'                   => 'QC Failed',
                    'updated'                     => 'Order Updated',
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

}