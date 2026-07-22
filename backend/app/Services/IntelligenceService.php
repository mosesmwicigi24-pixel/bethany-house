<?php

namespace App\Services;

use App\Models\{
    InventoryItem, Material, MaterialInventory,
    PurchaseOrder, PurchaseOrderItem, Supplier,
    ProductionOrder, ProductionTask, ProductionStage, BillOfMaterial,
    MaterialAllocation, Order, OrderItem, Product,
    Customer, User, Expense, ExpenseBudget, ExpenseCategory
};
use App\Support\CountryInference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * IntelligenceService
 *
 * Central service for all platform intelligence features:
 *
 *  1. autoReorderSuggestion()   — draft a PO when stock hits reorder point
 *  2. tailorWorkloadSnapshot()  — queue depth + avg completion per tailor
 *  3. churnRiskCustomers()      — customers overdue for their next purchase
 *  4. materialShortagePreFlight()— aggregate BOM demand vs available stock
 *  5. orderToProductionLink()   — auto-draft production order for producible items
 *  6. expenseBudgetWarnings()   — categories exceeding 80% budget mid-period
 *  9. smartTaskSort()           — sort tasks by deadline-miss risk score
 * 10. entityChipPreviews()      — rich status data for message entity chips
 */
class IntelligenceService
{
    // =========================================================================
    // 1. AUTO REORDER — draft a PO when stock hits threshold
    // =========================================================================

    /**
     * When an InventoryItem drops to/below its reorder_point, draft a PO.
     *
     * Preferred supplier = the one used most in the last 6 months for this product.
     * Returns the created PO or null if one is already pending for this item.
     *
     * Called from: InventoryController after any stock-reducing transaction.
     */
    public static function autoReorderSuggestion(InventoryItem $item, int $triggeredBy): ?PurchaseOrder
    {
        $reorderQty = $item->reorder_quantity ?? ($item->reorder_point * 2) ?? 10;
        if ($reorderQty <= 0) return null;

        // Don't double-draft — check for existing open draft POs for this product
        $existingDraft = PurchaseOrder::where('status', 'draft')
            ->whereHas('items', fn ($q) =>
                $q->where('product_id', $item->product_id)
                  ->where('item_type', 'product')
            )->first();

        if ($existingDraft) return null;

        // Find preferred supplier from recent PO history
        $preferredSupplierId = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.product_id', $item->product_id)
            ->where('purchase_orders.created_at', '>=', now()->subMonths(6))
            ->whereNotNull('purchase_orders.supplier_id')
            ->groupBy('purchase_orders.supplier_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('purchase_orders.supplier_id');

        // Fall back to first active supplier
        if (!$preferredSupplierId) {
            $preferredSupplierId = Supplier::where('status', 'active')->value('id');
        }
        if (!$preferredSupplierId) return null;

        // Get last unit cost
        $lastUnitCost = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.product_id', $item->product_id)
            ->where('purchase_orders.supplier_id', $preferredSupplierId)
            ->orderByDesc('purchase_orders.created_at')
            ->value('purchase_order_items.unit_price') ?? 0;

        $totalAmount = $lastUnitCost * $reorderQty;
        $poNumber    = 'AUTO-' . strtoupper(Str::random(6));
        while (PurchaseOrder::where('po_number', $poNumber)->exists()) {
            $poNumber = 'AUTO-' . strtoupper(Str::random(6));
        }

        $productName = $item->product?->translations->firstWhere('language_code', 'en')?->name
            ?? $item->product?->sku ?? 'Product';

        DB::beginTransaction();
        try {
            $po = PurchaseOrder::create([
                'po_number'               => $poNumber,
                'supplier_id'             => $preferredSupplierId,
                'outlet_id'               => $item->outlet_id,
                'order_date'              => now()->toDateString(),
                'expected_delivery_date'  => now()->addDays(7)->toDateString(),
                'status'                  => 'draft',
                'currency_code'           => 'KES',
                'subtotal'                => $totalAmount,
                'tax_amount'              => 0,
                'shipping_amount'         => 0,
                'total_amount'            => $totalAmount,
                'notes'                   => "Auto-generated: {$productName} dropped to reorder level ({$item->quantity_on_hand} units). Suggested reorder: {$reorderQty} units.",
                'created_by'              => $triggeredBy,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_type'         => 'product',
                'product_id'        => $item->product_id,
                'product_variant_id'=> $item->product_variant_id,
                'description'       => $productName,
                'quantity'          => $reorderQty,
                'unit_price'        => $lastUnitCost,
                'total_price'       => $totalAmount,
                'quantity_received' => 0,
            ]);

            DB::commit();

            // Notify procurement managers
            NotificationService::purchaseOrderCreated(
                $po->id,
                $po->po_number,
                Supplier::find($preferredSupplierId)?->name ?? 'Supplier',
                $totalAmount,
                'KES'
            );

            try {
                ActivityLogService::log('auto_reorder_draft_created', $po, [
                    'product_id'       => $item->product_id,
                    'product_name'     => $productName,
                    'trigger_qty'      => $item->quantity_on_hand,
                    'reorder_qty'      => $reorderQty,
                    'preferred_supplier' => $preferredSupplierId,
                ]);
            } catch (\Exception) {}

            return $po;
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::warning('[Intelligence] Auto-reorder failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // 2. TAILOR WORKLOAD SNAPSHOT
    // =========================================================================

    /**
     * Returns workload data for all active tailors:
     *  - active_tasks:       count of in_progress + pending tasks
     *  - overdue_tasks:      tasks on orders past due_date
     *  - avg_hours_per_task: historical average actual_hours
     *  - completion_rate:    completed / total assigned last 30d (%)
     *
     * Used by ProductionController::assign() to surface workload at assignment time.
     */
    public static function tailorWorkloadSnapshot(): array
    {
        $tailors = User::whereHas('roles', fn ($q) =>
            $q->whereIn('name', ['tailor', 'production_worker'])
        )
        ->where('status', 'active')
        ->select('id', 'first_name', 'last_name', 'email')
        ->get();

        return $tailors->map(function (User $tailor) {
            $activeTasks = ProductionTask::where('assigned_to', $tailor->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();

            $overdueTasks = ProductionTask::where('assigned_to', $tailor->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereHas('productionOrder', fn ($q) =>
                    $q->where('due_date', '<', now())
                      ->whereNotIn('status', ['completed', 'cancelled'])
                )
                ->count();

            $avgHours = ProductionTask::where('assigned_to', $tailor->id)
                ->where('status', 'completed')
                ->whereNotNull('actual_hours')
                ->avg('actual_hours') ?? 0;

            $thirtyDaysAgo = now()->subDays(30);
            $totalAssigned = ProductionTask::where('assigned_to', $tailor->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count();
            $totalCompleted = ProductionTask::where('assigned_to', $tailor->id)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $thirtyDaysAgo)
                ->count();
            $completionRate = $totalAssigned > 0
                ? round(($totalCompleted / $totalAssigned) * 100)
                : 100;

            // Workload score: higher = more loaded (for sorting)
            $workloadScore = ($activeTasks * 10) + ($overdueTasks * 20);

            return [
                'id'              => $tailor->id,
                'name'            => trim("{$tailor->first_name} {$tailor->last_name}"),
                'active_tasks'    => $activeTasks,
                'overdue_tasks'   => $overdueTasks,
                'avg_hours_per_task' => round((float) $avgHours, 1),
                'completion_rate' => $completionRate,
                'workload_score'  => $workloadScore,
                'recommendation'  => $workloadScore === 0
                    ? 'available'
                    : ($workloadScore <= 20 ? 'light' : ($workloadScore <= 50 ? 'moderate' : 'heavy')),
            ];
        })
        ->sortBy('workload_score')
        ->values()
        ->toArray();
    }

    // =========================================================================
    // 3. CUSTOMER CHURN RISK
    // =========================================================================

    /**
     * Identifies customers at risk of churning:
     * at-risk = time since last purchase > 2× their average purchase interval.
     *
     * Only considers customers with ≥ 2 orders (need interval to calculate).
     * Returns at-risk customers sorted by estimated days overdue descending.
     */
    public static function churnRiskCustomers(int $limit = 50): array
    {
        // Get customers with their order history
        $customers = DB::table('orders')
            ->join('customers', 'customers.user_id', '=', 'orders.user_id')
            ->whereIn('orders.payment_status', ['paid', 'partial'])
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereNotNull('orders.user_id')
            ->groupBy(
                'orders.user_id',
                'customers.id',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.customer_email',
                'orders.customer_phone',
                'customers.loyalty_points'
            )
            ->having(DB::raw('COUNT(orders.id)'), '>=', 2)
            ->selectRaw("
                orders.user_id,
                customers.id AS customer_id,
                orders.customer_first_name,
                orders.customer_last_name,
                orders.customer_email,
                orders.customer_phone,
                customers.loyalty_points,
                COUNT(orders.id) AS total_orders,
                MAX(orders.created_at) AS last_order_at,
                MIN(orders.created_at) AS first_order_at,
                SUM(orders.total_amount) AS lifetime_value
            ")
            ->get();

        $atRisk = [];

        foreach ($customers as $c) {
            $lastOrder  = Carbon::parse($c->last_order_at);
            $firstOrder = Carbon::parse($c->first_order_at);

            // Average interval = span / (orders - 1) in days
            $spanDays       = $firstOrder->diffInDays($lastOrder);
            $avgIntervalDays = $c->total_orders > 1 ? $spanDays / ($c->total_orders - 1) : 30;

            $daysSinceLast  = $lastOrder->diffInDays(now());
            $overdueBy      = $daysSinceLast - ($avgIntervalDays * 2);

            if ($overdueBy > 0) {
                $atRisk[] = [
                    'customer_id'         => $c->customer_id,
                    'user_id'             => $c->user_id,
                    'name'                => trim("{$c->customer_first_name} {$c->customer_last_name}"),
                    'email'               => $c->customer_email,
                    'phone'               => $c->customer_phone,
                    'total_orders'        => (int) $c->total_orders,
                    'lifetime_value'      => (float) $c->lifetime_value,
                    'loyalty_points'      => (int) $c->loyalty_points,
                    'last_order_at'       => $c->last_order_at,
                    'days_since_last'     => (int) $daysSinceLast,
                    'avg_interval_days'   => (int) round($avgIntervalDays),
                    'overdue_by_days'     => (int) round($overdueBy),
                    'risk_level'          => $overdueBy > ($avgIntervalDays * 3) ? 'high' : 'medium',
                ];
            }
        }

        // Sort by overdue_by_days descending, limit
        usort($atRisk, fn ($a, $b) => $b['overdue_by_days'] - $a['overdue_by_days']);
        return array_slice($atRisk, 0, $limit);
    }

    // =========================================================================
    // 4. MATERIAL SHORTAGE PRE-FLIGHT
    // =========================================================================

    /**
     * Checks aggregate material demand across ALL pending/in-progress production
     * orders against available stock. Returns shortages that current individual
     * order checks would miss (because they don't see competing demand).
     *
     * Called when creating a new production order, or on-demand from the dashboard.
     */
    public static function materialShortagePreFlight(?int $newOrderProductId = null, int $newOrderQty = 0): array
    {
        // Aggregate all unmet material requirements from active orders
        $activeOrders = ProductionOrder::with(['materialAllocations.material'])
            ->whereIn('status', ['draft', 'pending', 'in_progress'])
            ->get();

        // Tally demand per material across all orders
        $demand = [];
        foreach ($activeOrders as $order) {
            $bom = BillOfMaterial::with('items.material')
                ->where('product_id', $order->product_id)
                ->where('is_active', true)
                ->first();

            if (!$bom) continue;

            foreach ($bom->items as $bomItem) {
                $required   = $bomItem->quantity * $order->quantity;
                $allocated  = $order->materialAllocations
                    ->firstWhere('material_id', $bomItem->material_id)
                    ?->quantity_allocated ?? 0;
                $stillNeeded = max(0, $required - $allocated);

                $mid = $bomItem->material_id;
                if (!isset($demand[$mid])) {
                    $demand[$mid] = [
                        'material_id'    => $mid,
                        'material_name'  => $bomItem->material?->name ?? 'Material',
                        'material_code'  => $bomItem->material?->code,
                        'unit'           => $bomItem->material?->unit_of_measure,
                        'total_needed'   => 0,
                        'orders_needing' => 0,
                    ];
                }
                $demand[$mid]['total_needed']   += $stillNeeded;
                $demand[$mid]['orders_needing'] += 1;
            }
        }

        // Add demand from proposed new order if provided
        if ($newOrderProductId && $newOrderQty > 0) {
            $newBom = BillOfMaterial::with('items.material')
                ->where('product_id', $newOrderProductId)
                ->where('is_active', true)
                ->first();

            if ($newBom) {
                foreach ($newBom->items as $bomItem) {
                    $mid = $bomItem->material_id;
                    if (!isset($demand[$mid])) {
                        $demand[$mid] = [
                            'material_id'    => $mid,
                            'material_name'  => $bomItem->material?->name ?? 'Material',
                            'material_code'  => $bomItem->material?->code,
                            'unit'           => $bomItem->material?->unit_of_measure,
                            'total_needed'   => 0,
                            'orders_needing' => 0,
                        ];
                    }
                    $demand[$mid]['total_needed']   += $bomItem->quantity * $newOrderQty;
                    $demand[$mid]['orders_needing'] += 1;
                }
            }
        }

        // Compare demand vs actual stock
        $shortages = [];
        foreach ($demand as $mid => $d) {
            $available = (float) (MaterialInventory::where('material_id', $mid)->value('quantity_on_hand') ?? 0);
            $shortfall  = $d['total_needed'] - $available;

            if ($shortfall > 0) {
                $shortages[] = array_merge($d, [
                    'available'  => $available,
                    'shortfall'  => round($shortfall, 2),
                    'severity'   => $available <= 0 ? 'out_of_stock' : 'insufficient',
                ]);
            }
        }

        usort($shortages, fn ($a, $b) => $b['shortfall'] <=> $a['shortfall']);
        return $shortages;
    }

    // =========================================================================
    // 5. ORDER → PRODUCTION AUTO-LINK
    // =========================================================================

    /**
     * When a sales order is placed/confirmed for a producible item,
     * automatically draft a linked production order.
     *
     * Checks:
     *  - product.is_producible = true
     *  - Active BOM exists
     *  - Insufficient stock to fulfil from inventory
     *  - No production order already linked to this order_item
     *
     * Returns array of created draft ProductionOrder IDs.
     */
    public static function autoLinkOrderToProduction(Order $order): array
    {
        $created = [];

        foreach ($order->items as $item) {
            // Skip if already has a linked production order
            $alreadyLinked = ProductionOrder::where('order_item_id', $item->id)->exists();
            if ($alreadyLinked) continue;

            $product = Product::find($item->product_id);
            if (!$product || !$product->is_producible) continue;

            // Check BOM
            $bom = BillOfMaterial::where('product_id', $product->id)->where('is_active', true)->first();
            if (!$bom) continue;

            // Check available stock
            $inventoryItem  = InventoryItem::where('product_id', $product->id)
                ->when($item->product_variant_id, fn ($q) => $q->where('product_variant_id', $item->product_variant_id))
                ->first();
            $available = max(0, ($inventoryItem?->quantity_on_hand ?? 0) - ($inventoryItem?->quantity_reserved ?? 0));

            if ($available >= $item->quantity) continue; // enough stock — no production needed

            $toProduceQty = $item->quantity - $available;
            $productName  = $product->translations->firstWhere('language_code', 'en')?->name ?? $product->sku;

            // Generate order number
            $prefix = 'PRD-AUTO-';
            $poNum  = $prefix . strtoupper(Str::random(6));
            while (ProductionOrder::where('order_number', $poNum)->exists()) {
                $poNum = $prefix . strtoupper(Str::random(6));
            }

            // Due date: order date + 7 days, or order's requested delivery
            $dueDate = now()->addDays(7)->toDateString();

            DB::beginTransaction();
            try {
                $prodOrder = ProductionOrder::create([
                    'order_number'       => $poNum,
                    'product_id'         => $product->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity'           => $toProduceQty,
                    'priority'           => 'normal',
                    'due_date'           => $dueDate,
                    'customer_order_id'  => $order->id,
                    'order_item_id'      => $item->id,
                    'outlet_id'          => $order->outlet_id,
                    'status'             => 'draft',
                    'is_customer_order'  => true,
                    'notes'              => "Auto-created for Sales Order #{$order->order_number}. "
                                          . "Produce {$toProduceQty}× {$productName} (stock: {$available}, needed: {$item->quantity}).",
                    'created_by'         => $order->created_by ?? $order->user_id,
                ]);

                DB::commit();
                $created[] = $prodOrder->id;

                // Notify production managers
                NotificationService::productionOrderCreated(
                    $prodOrder->id,
                    $prodOrder->order_number,
                    $productName,
                    $toProduceQty
                );
            } catch (\Exception $e) {
                DB::rollBack();
                \Illuminate\Support\Facades\Log::warning('[Intelligence] Auto-production link failed: ' . $e->getMessage());
            }
        }

        return $created;
    }

    // =========================================================================
    // 6. EXPENSE BUDGET WARNINGS
    // =========================================================================

    /**
     * Checks all active monthly expense budgets and returns warnings for
     * categories that have exceeded 80% utilization.
     *
     * Designed to run:
     *  - Via the scheduled command (daily nightly check)
     *  - On-demand from the expense dashboard endpoint
     */
    public static function expenseBudgetWarnings(): array
    {
        $now     = now();
        $year    = (int) $now->format('Y');
        $month   = (int) $now->format('n');
        $dayOfMonth  = (int) $now->format('j');
        $daysInMonth = (int) $now->daysInMonth;
        $periodProgress = $dayOfMonth / $daysInMonth; // 0–1

        $budgets = ExpenseBudget::with(['category', 'outlet'])
            ->where('period_type', 'monthly')
            ->where('period_year', $year)
            ->where('period_number', $month)
            ->where('budgeted_amount', '>', 0)
            ->get();

        $warnings = [];

        foreach ($budgets as $budget) {
            $utilization = $budget->utilizationPercent();

            // Warn at 80%+ if past 40% of the month, or 100%+ at any time
            $warnThreshold = 80;
            if ($utilization < $warnThreshold) continue;
            if ($utilization < 100 && $periodProgress < 0.4) continue;

            $severity = $utilization >= 100 ? 'exceeded' : 'warning';

            $warnings[] = [
                'budget_id'          => $budget->id,
                'category_id'        => $budget->category_id,
                'category_name'      => $budget->category?->name ?? 'Unknown',
                'outlet_id'          => $budget->outlet_id,
                'outlet_name'        => $budget->outlet?->name ?? 'Company-wide',
                'budgeted_amount'    => (float) $budget->budgeted_amount,
                'actual_spend'       => $budget->actualSpend(),
                'utilization_percent'=> $utilization,
                'remaining'          => max(0, (float) $budget->budgeted_amount - $budget->actualSpend()),
                'severity'           => $severity,
                'period'             => "{$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            ];
        }

        usort($warnings, fn ($a, $b) => $b['utilization_percent'] <=> $a['utilization_percent']);
        return $warnings;
    }

    // =========================================================================
    // 9. SMART TASK SORT
    // =========================================================================

    /**
     * Sort a collection of production tasks by deadline-miss risk score.
     * Score = (days until due × -1) + (priority weight) + (hours remaining estimate)
     *
     * Higher score = higher risk = show first.
     */
    public static function smartTaskSort(array $tasks): array
    {
        $priorityWeights = ['urgent' => 40, 'high' => 20, 'normal' => 5, 'low' => 0];

        foreach ($tasks as &$task) {
            $dueDate     = Carbon::parse($task['production_order']['due_date'] ?? now()->addDays(30));
            $daysUntil   = now()->diffInDays($dueDate, false); // negative if overdue
            $priority    = $task['production_order']['priority'] ?? 'normal';
            $estHours    = $task['estimated_hours'] ?? 4;
            $status      = $task['status'] ?? 'pending';

            // Risk score:
            //   Overdue orders get max urgency
            //   Each day closer = +3 points
            //   Priority multiplier
            //   In-progress tasks slightly lower score (already started)
            $dayScore     = $daysUntil <= 0 ? 999 : max(0, (14 - $daysUntil) * 3);
            $priorityScore = $priorityWeights[$priority] ?? 5;
            $hoursScore   = min($estHours, 20);  // cap at 20
            $statusBonus  = $status === 'in_progress' ? -5 : 0; // already started = slightly lower urgency

            $task['risk_score'] = $dayScore + $priorityScore + $hoursScore + $statusBonus;
        }
        unset($task);

        usort($tasks, fn ($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        return $tasks;
    }

    // =========================================================================
    // 10. ENTITY CHIP PREVIEWS
    // =========================================================================

    /**
     * Returns rich preview data for entity chips in channel messages.
     * Called when a message with linked_entities is rendered.
     *
     * Input: [['type' => 'order', 'id' => 123], ['type' => 'production_order', 'id' => 56]]
     * Output: keyed by "type:id" with status, meta, url, colour
     */
    public static function entityChipPreviews(array $entities): array
    {
        $previews = [];

        foreach ($entities as $entity) {
            $key = "{$entity['type']}:{$entity['id']}";

            if ($entity['type'] === 'order') {
                $order = Order::select(
                    'id', 'order_number', 'status', 'payment_status',
                    'total_amount', 'currency_code',
                    'customer_first_name', 'customer_last_name', 'created_at'
                )->find($entity['id']);

                if (!$order) continue;

                $previews[$key] = [
                    'type'       => 'order',
                    'id'         => $order->id,
                    'label'      => '#' . $order->order_number,
                    'status'     => $order->status,
                    'badge'      => self::orderStatusBadge($order->status),
                    'meta'       => $order->currency_code . ' ' . number_format($order->total_amount, 2),
                    'subtitle'   => trim("{$order->customer_first_name} {$order->customer_last_name}"),
                    'created_at' => $order->created_at,
                    'url'        => "/sales/orders/{$order->id}",
                    'payment_status' => $order->payment_status,
                ];

            } elseif ($entity['type'] === 'eod_report') {
                // A quoted report's chip carries the day's headline numbers, so the
                // channel can see what is being discussed without opening it.
                $r = DB::table('cash_register_eod_reports as r')
                    ->join('users as u',   'u.id', '=', 'r.user_id')
                    ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
                    ->where('r.id', $entity['id'])
                    ->select([
                        'r.id', 'r.report_date', 'r.submitted_at', 'r.acknowledged_at',
                        'o.name as outlet_name',
                        DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
                    ])
                    ->first();

                if (!$r) continue;

                $first = explode(' ', trim($r->user_name))[0] ?: 'report';

                $previews[$key] = [
                    'type'       => 'eod_report',
                    'id'         => $r->id,
                    'label'      => '#EOD-' . date('dMy', strtotime($r->report_date)) . '-' . $first,
                    'status'     => $r->acknowledged_at ? 'read' : 'unread',
                    'badge'      => $r->acknowledged_at ? 'success' : 'warning',
                    'meta'       => date('D, d M Y', strtotime($r->report_date)),
                    'subtitle'   => trim($r->user_name) . ' · ' . $r->outlet_name,
                    'created_at' => $r->submitted_at,
                    'url'        => "/pos/eod-reports?report={$r->id}",
                ];

            } elseif ($entity['type'] === 'production_order') {
                $po = ProductionOrder::with([
                    'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
                    'tasks',
                ])->find($entity['id']);

                if (!$po) continue;

                $totalTasks    = $po->tasks->count();
                $completedTasks = $po->tasks->where('status', 'completed')->count();
                $pct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

                $previews[$key] = [
                    'type'       => 'production_order',
                    'id'         => $po->id,
                    'label'      => '#' . $po->order_number,
                    'status'     => $po->status,
                    'badge'      => self::productionStatusBadge($po->status),
                    'meta'       => "{$completedTasks}/{$totalTasks} stages · {$pct}%",
                    'subtitle'   => $po->product?->translations->first()?->name ?? $po->product?->sku ?? 'Product',
                    'due_date'   => $po->due_date,
                    'priority'   => $po->priority,
                    'is_overdue' => $po->due_date && Carbon::parse($po->due_date)->isPast()
                                    && !in_array($po->status, ['completed', 'cancelled']),
                    'url'        => "/production/orders/{$po->id}",
                ];
            }
        }

        return $previews;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function orderStatusBadge(string $status): array
    {
        return match ($status) {
            'pending'    => ['label' => 'Pending',    'color' => 'warning'],
            'processing' => ['label' => 'Processing', 'color' => 'info'],
            'completed'  => ['label' => 'Completed',  'color' => 'success'],
            'shipped'    => ['label' => 'Shipped',    'color' => 'info'],
            'delivered'  => ['label' => 'Delivered',  'color' => 'success'],
            'cancelled'  => ['label' => 'Cancelled',  'color' => 'danger'],
            default      => ['label' => ucfirst(str_replace('_', ' ', $status)), 'color' => 'neutral'],
        };
    }

    private static function productionStatusBadge(string $status): array
    {
        return match ($status) {
            'draft'       => ['label' => 'Draft',       'color' => 'neutral'],
            'pending'     => ['label' => 'Queued',      'color' => 'warning'],
            'in_progress' => ['label' => 'In Progress', 'color' => 'brand'],
            'qc_pending'  => ['label' => 'QC Pending',  'color' => 'warning'],
            'qc_passed'   => ['label' => 'QC Passed',   'color' => 'success'],
            'completed'   => ['label' => 'Completed',   'color' => 'success'],
            'on_hold'     => ['label' => 'On Hold',     'color' => 'danger'],
            'cancelled'   => ['label' => 'Cancelled',   'color' => 'danger'],
            default       => ['label' => ucfirst(str_replace('_', ' ', $status)), 'color' => 'neutral'],
        };
    }

    // =========================================================================
    // Customer geography — "which country has more customers?"
    //
    // Built entirely from order data we already hold (no new instrumentation):
    // every order carries a resolved country (customer → shipping → billing).
    // A customer's country is their MOST RECENT order's country, so a buyer who
    // relocates is counted once, where they are now. Guest orders (no identity)
    // are excluded from the customer head-count but still counted in the
    // orders/revenue geography so totals stay honest. Money is NOT summed across
    // currencies — each country reports its own dominant currency.
    // =========================================================================
    public static function customerGeography(): array
    {
        // One row per identified customer = their LATEST order, carrying every
        // location signal we hold (order country codes + phone). Phone is joined
        // by customer_id first, then user_id, then the order's own phone.
        $perCustomer = DB::select("
            SELECT DISTINCT ON (cust_key)
                   COALESCE(o.customer_id, c2.id) AS cust_key,
                   o.customer_country_code, o.shipping_country_code, o.billing_country_code,
                   COALESCE(NULLIF(o.customer_phone,''), c1.phone, c2.phone) AS phone
            FROM orders o
            LEFT JOIN customers c1 ON c1.id = o.customer_id
            LEFT JOIN customers c2 ON c2.user_id = o.user_id
            WHERE (o.customer_id IS NOT NULL OR o.user_id IS NOT NULL)
              AND o.status NOT IN ('cancelled','voided','refunded')
            ORDER BY cust_key, o.created_at DESC
        ");

        // Every live order (incl. guests) for the orders/revenue geography.
        $perOrders = DB::select("
            SELECT o.customer_country_code, o.shipping_country_code, o.billing_country_code,
                   COALESCE(NULLIF(o.customer_phone,''), c1.phone, c2.phone) AS phone,
                   o.total_amount, o.currency_code
            FROM orders o
            LEFT JOIN customers c1 ON c1.id = o.customer_id
            LEFT JOIN customers c2 ON c2.user_id = o.user_id
            WHERE o.status NOT IN ('cancelled','voided','refunded')
        ");

        $names = DB::table('countries')->pluck('name', 'code')->toArray();

        $row = fn (string $code) => [
            'country_code' => $code,
            'country_name' => $names[$code] ?? $code,
            'customers'    => 0,
            'orders'       => 0,
            'revenue'      => 0.0,
            'currency'     => null,
        ];

        // Customers per resolved country (order country → phone prefix → null).
        $byCode    = [];
        $unlocated = 0;
        foreach ($perCustomer as $r) {
            $code = CountryInference::resolve(
                [$r->customer_country_code, $r->shipping_country_code, $r->billing_country_code],
                $r->phone,
            );
            if ($code === null) { $unlocated++; continue; }
            $byCode[$code] ??= $row($code);
            $byCode[$code]['customers']++;
        }

        // Orders + revenue per country, with each country's dominant currency.
        $currencyTally = [];
        foreach ($perOrders as $r) {
            $code = CountryInference::resolve(
                [$r->customer_country_code, $r->shipping_country_code, $r->billing_country_code],
                $r->phone,
            );
            if ($code === null) { continue; }
            $byCode[$code] ??= $row($code);
            $byCode[$code]['orders']++;
            $byCode[$code]['revenue'] += (float) $r->total_amount;
            $cur = $r->currency_code ?: 'KES';
            $currencyTally[$code][$cur] = ($currencyTally[$code][$cur] ?? 0) + 1;
        }
        foreach ($currencyTally as $code => $tally) {
            arsort($tally);
            $byCode[$code]['currency'] = array_key_first($tally);
        }

        // Rank by customer head-count, then revenue.
        $countries = array_values($byCode);
        usort($countries, fn ($a, $b) =>
            [$b['customers'], $b['revenue']] <=> [$a['customers'], $a['revenue']]);

        $locatedCustomers = array_sum(array_column($countries, 'customers'));

        return [
            'countries' => $countries,
            'summary'   => [
                'located_customers'   => $locatedCustomers,
                'unlocated_customers' => $unlocated,
                'distinct_countries'  => count(array_filter($countries, fn ($c) => $c['customers'] > 0)),
                'top_country_code'    => $countries[0]['country_code'] ?? null,
                'top_country_name'    => $countries[0]['country_name'] ?? null,
            ],
        ];
    }
}