<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\DataScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use App\Services\ActivityLogService;

class StockAdjustmentsController extends Controller
{
    // ── Reason codes ──────────────────────────────────────────────────────────

    const REASON_CODES = [
        // Decreases
        'damaged'          => ['label' => 'Damaged',           'direction' => 'decrease', 'requires_approval' => false],
        'stolen'           => ['label' => 'Stolen / Lost',     'direction' => 'decrease', 'requires_approval' => true],
        'expired'          => ['label' => 'Expired',           'direction' => 'decrease', 'requires_approval' => false],
        'shrinkage'        => ['label' => 'Shrinkage',         'direction' => 'decrease', 'requires_approval' => false],
        // Increases
        'found'            => ['label' => 'Found / Recovered', 'direction' => 'increase', 'requires_approval' => true],
        'supplier_return'  => ['label' => 'Supplier Return',   'direction' => 'increase', 'requires_approval' => false],
        // Neutral / either
        'correction'       => ['label' => 'Correction',        'direction' => 'either',   'requires_approval' => true],
        'stock_count'      => ['label' => 'Stock Count',       'direction' => 'either',   'requires_approval' => false],
        'recount'          => ['label' => 'Recount',           'direction' => 'either',   'requires_approval' => false],
        'transfer'         => ['label' => 'Internal Transfer', 'direction' => 'either',   'requires_approval' => false],
        'other'            => ['label' => 'Other',             'direction' => 'either',   'requires_approval' => true],
    ];

    // =========================================================================
    // GET /api/v1/admin/inventory/adjustments
    // Paginated list of all adjustments with filters.
    // =========================================================================

    /**
     * Adjustments this caller may see (Phase 4A): an adjustment belongs to the
     * outlet of the stock row it moved. Bounded callers (outlet manager,
     * cashier) see their outlets'; another outlet's id answers 404.
     */
    private function scoped()
    {
        $query = InventoryTransaction::query();
        $ids   = DataScopeResolver::outletIdsForUnowned(request()->user(), 'inventory.view');
        if ($ids !== null) {
            $query->whereIn('inventory_transactions.inventory_item_id',
                DB::table('inventory_items')->select('id')->whereIn('outlet_id', $ids));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 25), 100);

        $query = $this->scoped()->with([
            'inventoryItem.product:id,sku',
            'inventoryItem.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'inventoryItem.product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'inventoryItem.variant:id,sku,variant_name',
            'inventoryItem.outlet:id,name',
            'createdBy:id,first_name,last_name,email',
            'approvedBy:id,first_name,last_name,email',
        ])
        ->whereIn('transaction_type', ['adjustment', 'damaged', 'stolen', 'found', 'correction',
            'stock_count', 'expired', 'shrinkage', 'supplier_return', 'recount', 'other']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('reason_code')) {
            $query->where('reason_code', $request->reason_code);
        }
        if ($request->filled('outlet_id')) {
            $query->whereHas('inventoryItem', fn ($q) =>
                $q->where('outlet_id', $request->outlet_id)
            );
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('inventoryItem.product', fn ($q) =>
                $q->where('sku', 'ILIKE', "%{$search}%")
                  ->orWhereHas('translations', fn ($t) =>
                      $t->where('language_code', 'en')->where('name', 'ILIKE', "%{$search}%")
                  )
            );
        }

        $adjustments = $query->orderByDesc('created_at')->paginate($perPage);

        // Stats - count all adjustment transactions regardless of status
        $adjustmentTypes = array_keys(self::REASON_CODES);
        $baseQuery = fn () => $this->scoped()->whereIn('transaction_type', $adjustmentTypes);

        $stats = [
            'total'            => $baseQuery()->count(),
            'pending_approval' => $baseQuery()->where('status', 'pending_approval')->count(),
            'approved'         => $baseQuery()->whereIn('status', ['approved', null, ''])->count(),
            'rejected'         => $baseQuery()->where('status', 'rejected')->count(),
            'total_shrinkage'  => (int) abs(
                $baseQuery()
                    ->whereIn('transaction_type', ['damaged', 'stolen', 'expired', 'shrinkage'])
                    ->where('quantity_change', '<', 0)
                    ->sum('quantity_change')
            ),
        ];

        return response()->json([
            'data'  => collect($adjustments->items())->map(fn ($t) => $this->formatAdjustment($t)),
            'meta'  => [
                'current_page' => $adjustments->currentPage(),
                'last_page'    => $adjustments->lastPage(),
                'total'        => $adjustments->total(),
                'from'         => $adjustments->firstItem(),
                'to'           => $adjustments->lastItem(),
            ],
            'stats'        => $stats,
            'reason_codes' => self::REASON_CODES,
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/adjustments/{id}
    // =========================================================================

    public function show($id)
    {
        $adjustment = $this->scoped()->with([
            'inventoryItem.product.translations',
            'inventoryItem.product.images' => fn ($q) => $q->where('is_primary', true),
            'inventoryItem.variant',
            'inventoryItem.outlet',
            'createdBy:id,first_name,last_name,email',
            'approvedBy:id,first_name,last_name,email',
        ])->findOrFail($id);

        return response()->json(['adjustment' => $this->formatAdjustment($adjustment, true)]);
    }

    // =========================================================================
    // POST /api/v1/admin/inventory/adjustments
    // Create a new adjustment. Requires approval based on reason code.
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'quantity_change'   => 'required|integer|not_in:0',
            'reason_code'       => 'required|in:' . implode(',', array_keys(self::REASON_CODES)),
            'notes'             => 'nullable|string|max:1000',
            'reference_number'  => 'nullable|string|max:100',
        ]);

        // A stock row at an outlet outside the caller's scope is absent.
        $item         = InventoryItem::query()
            ->tap(fn ($q) => DataScopeResolver::boundToOutlets($q, $request->user(), 'inventory.view', 'inventory_items.outlet_id'))
            ->findOrFail($validated['inventory_item_id']);
        $reasonConfig = self::REASON_CODES[$validated['reason_code']];
        $change       = $validated['quantity_change'];

        // Whether an adjustment waits for approval (Phase 3B). It is valued
        // at |quantity| × the product's KES book cost (CostBasis). A reason
        // code that never needed approval still applies at once — but ONLY
        // while the value, on the rolling 24-hour total of this person's
        // adjustments to this item, is within the procurement manager's band.
        // Above it, or with no cost on the book (a value that cannot be
        // stated is never guessed), it waits like any other. Admins and super
        // admins wait too: whoever originates a stock event never approves it
        // (Phase 1B).
        $user          = auth()->user();
        $engine        = app(\App\Services\Approvals\ApprovalEngine::class);
        $valueKes      = \App\Services\Approvals\Handlers\StockAdjustmentHandler::valueKes($item->id, (int) $change);
        $appliedKes    = $this->appliedWithoutApprovalKes($user->id, $item->id);
        $basisKes      = $appliedKes === null ? null
            : $engine->rollingBasis('stock_adjustment', $user->id, "inventory_item:{$item->id}", $valueKes, null, $appliedKes);
        $pmBand        = $engine->thresholds()->bands('stock_adjustment')->first();
        $withinPmBand  = $basisKes !== null && $pmBand !== null
            && ($pmBand->up_to_kes === null || $basisKes <= (float) $pmBand->up_to_kes);
        $requiresApproval = $reasonConfig['requires_approval'] || !$withinPmBand;

        // Direction validation
        if ($reasonConfig['direction'] === 'decrease' && $change > 0) {
            return response()->json([
                'message' => "Reason '{$reasonConfig['label']}' requires a negative quantity change.",
            ], 422);
        }
        if ($reasonConfig['direction'] === 'increase' && $change < 0) {
            return response()->json([
                'message' => "Reason '{$reasonConfig['label']}' requires a positive quantity change.",
            ], 422);
        }

        // Prevent going negative (only for immediate apply - pending can be caught at approval)
        if (!$requiresApproval && $change < 0) {
            if ($item->quantity_on_hand + $change < 0) {
                return response()->json([
                    'message' => "Adjustment would result in negative stock. Available: {$item->quantity_on_hand}.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $quantityBefore = $item->quantity_on_hand;

            $transaction = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'transaction_type'  => $validated['reason_code'],
                'reference_type'    => 'adjustment',
                'reference_number'  => $validated['reference_number'] ?? null,
                'quantity_change'   => $change,
                'quantity_before'   => $quantityBefore,
                'quantity_after'    => $requiresApproval
                    ? $quantityBefore               // Stock not changed yet
                    : $quantityBefore + $change,
                'notes'             => $validated['notes'] ?? null,
                'reason_code'       => $validated['reason_code'],
                'status'            => $requiresApproval ? 'pending_approval' : 'approved',
                'created_by'        => $user->id,
                // A reason that needs no approval applies at once, recorded
                // against the person who made it.
                'approved_by'       => $requiresApproval ? null : $user->id,
                'approved_at'       => $requiresApproval ? null : now(),
                'approval_notes'    => null,
            ]);

            // Apply immediately if no approval needed
            if (!$requiresApproval) {
                $item->increment('quantity_on_hand', $change);
            }

            $approval = $requiresApproval
                ? $engine->submit('stock_adjustment', $transaction, $user, null, (float) ($appliedKes ?? 0))
                : null;

            DB::commit();

            // ── Audit log ─────────────────────────────────────────────────────
            $productName = $item->product?->translations?->first()?->name ?? $item->product?->sku ?? 'Unknown';
            $sku         = $item->variant?->sku ?? $item->product?->sku ?? 'N/A';
            $outletName  = $item->outlet?->name ?? 'Warehouse';
            $sign        = $change > 0 ? '+' : '';
            ActivityLogService::log('adjustment_created', null, [
                'transaction_id'   => $transaction->id,
                'product_name'     => $productName,
                'sku'              => $sku,
                'outlet'           => $outletName,
                'quantity_before'  => $quantityBefore,
                'quantity_change'  => $change,
                'quantity_after'   => $quantityBefore + ($requiresApproval ? 0 : $change),
                'reason_code'      => $validated['reason_code'],
                'reason_label'     => $reasonConfig['label'],
                'status'           => $requiresApproval ? 'pending_approval' : 'approved',
            ], "Stock adjustment: {$sku} {$sign}{$change} at {$outletName} - {$reasonConfig['label']}" . ($requiresApproval ? ' (pending approval)' : ''));

            // ── Notification (only for pending-approval adjustments) ───────────
            if ($requiresApproval) {
                $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
                NotificationService::stockAdjustmentPendingApproval(
                    $transaction->id,
                    $productName,
                    $sku,
                    $change,
                    $reasonConfig['label'],
                    $userName
                );
            }

            return response()->json([
                'message'           => $requiresApproval
                    ? 'Adjustment submitted for approval.'
                    : 'Adjustment applied successfully.',
                'adjustment'        => $this->formatAdjustment($transaction->fresh()->load([
                    'inventoryItem.product.translations',
                    'inventoryItem.variant',
                    'inventoryItem.outlet',
                    'createdBy:id,first_name,last_name,email',
                    'approvedBy:id,first_name,last_name,email',
                ])),
                'requires_approval' => $requiresApproval,
                // Kept for older clients; no role skips approval any more.
                'auto_approved'     => false,
                'value_kes'         => $valueKes,
                'approval'          => $approval ? $engine->present($approval->load(['signatures', 'maker']), $user) : null,
            ], 201);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save adjustment.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * KES value of this person's adjustments to this item in the last 24
     * hours that applied without approval (reason codes under the PM band),
     * for the anti-splitting total. Null when any of them cannot be valued.
     */
    private function appliedWithoutApprovalKes(int $userId, int $itemId): ?float
    {
        $total = 0.0;
        $rows = InventoryTransaction::where('inventory_item_id', $itemId)
            ->where('reference_type', 'adjustment')
            ->where('created_by', $userId)
            ->whereColumn('approved_by', 'created_by')
            ->where('status', 'approved')
            ->where('created_at', '>=', now()->subDay())
            ->get(['quantity_change']);
        foreach ($rows as $row) {
            $v = \App\Services\Approvals\Handlers\StockAdjustmentHandler::valueKes($itemId, (int) $row->quantity_change);
            if ($v === null) {
                return null;
            }
            $total += $v;
        }

        return $total;
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/adjustments/{id}/approve
    // Approve a pending adjustment - applies the quantity change.
    // =========================================================================

    public function approve(Request $request, $id)
    {
        $transaction = $this->scoped()->where('status', 'pending_approval')->findOrFail($id);

        // Since Phase 3B this SIGNS the band the adjustment is waiting on. The
        // engine refuses its raiser (maker ≠ checker), checks the caller holds
        // the band, and applies the stock (StockAdjustmentHandler, re-checking
        // it cannot go negative) only when the last band has signed.
        $engine   = app(\App\Services\Approvals\ApprovalEngine::class);
        $approval = $engine->sign(
            $engine->openOrAdopt('stock_adjustment', $transaction), $request->user(),
            \App\Models\ApprovalSignature::APPROVED, $request->get('notes'), $transaction->id,
        );
        $done = $approval->status === \App\Models\ApprovalRequest::APPROVED;

        return response()->json([
            'message'    => $done ? 'Adjustment approved and applied.' : 'Signed. The adjustment now waits for the next band.',
            'adjustment' => $this->formatAdjustment($transaction->fresh()->load([
                'inventoryItem', 'createdBy:id,first_name,last_name,email',
                'approvedBy:id,first_name,last_name,email',
            ])),
            'approval'   => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
        ]);
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/adjustments/{id}/reject
    // Reject a pending adjustment - no stock change.
    // =========================================================================

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $transaction = $this->scoped()->where('status', 'pending_approval')->findOrFail($id);

        $engine = app(\App\Services\Approvals\ApprovalEngine::class);
        $engine->sign(
            $engine->openOrAdopt('stock_adjustment', $transaction), $request->user(),
            \App\Models\ApprovalSignature::REJECTED, $request->reason, $transaction->id,
        );

        return response()->json(['message' => 'Adjustment rejected.']);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/adjustments/{id}/audit-log
    // =========================================================================

    public function auditLog($id)
    {
        $this->scoped()->findOrFail($id); // 404 guard — scope included

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->whereRaw("al.properties::text LIKE ?", ["%\"transaction_id\":{$id}%"])
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id', 'al.event', 'al.action', 'al.description',
                'al.properties', 'al.ip_address', 'al.created_at',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
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
    // GET /api/v1/admin/inventory/adjustments/pending
    // Only pending approvals - for the notification badge.
    // =========================================================================

    public function pending()
    {
        $items = $this->scoped()->with([
            'inventoryItem.product.translations' => fn ($q) => $q->where('language_code', 'en'),
            'inventoryItem.outlet:id,name',
            'inventoryItem.variant:id,variant_name',
            'createdBy:id,first_name,last_name,email',
        ])
        ->where('status', 'pending_approval')
        ->orderByDesc('created_at')
        ->get();

        return response()->json([
            'data'  => $items->map(fn ($t) => $this->formatAdjustment($t)),
            'count' => $items->count(),
        ]);
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/adjustments/{id}/reverse
    // Reverse an approved adjustment - creates a compensating transaction.
    // Only super_admin and admin can reverse.
    // =========================================================================

    public function reverse(Request $request, $id)
    {
        $original = $this->scoped()->with(['inventoryItem'])
            ->where('status', 'approved')
            ->whereNotNull('quantity_change')
            ->findOrFail($id);

        // Cannot reverse a reversal (prevent infinite loops)
        if (str_starts_with($original->notes ?? '', '[REVERSAL')) {
            return response()->json([
                'message' => 'This transaction is itself a reversal and cannot be reversed again.',
            ], 422);
        }

        // Cannot reverse already-reversed transactions
        $alreadyReversed = InventoryTransaction::where('reference_id', $original->id)
            ->where('reference_type', 'reversal')
            ->exists();

        if ($alreadyReversed) {
            return response()->json([
                'message' => 'This adjustment has already been reversed.',
            ], 422);
        }

        $item           = $original->inventoryItem;
        $reverseChange  = -$original->quantity_change;
        $user           = auth()->user();

        // Check won't go negative
        if ($reverseChange < 0 && ($item->quantity_on_hand + $reverseChange) < 0) {
            return response()->json([
                'message' => "Cannot reverse - would result in negative stock. Available: {$item->quantity_on_hand}.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $quantityBefore = $item->quantity_on_hand;
            $item->increment('quantity_on_hand', $reverseChange);
            $item->refresh();

            $reversal = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'transaction_type'  => 'correction',
                'reference_type'    => 'reversal',
                'reference_id'      => $original->id,
                'quantity_change'   => $reverseChange,
                'quantity_before'   => $quantityBefore,
                'quantity_after'    => $item->quantity_on_hand,
                'notes'             => "[REVERSAL of adjustment #{$original->id}] " . ($request->get('notes') ?? ''),
                'reason_code'       => 'correction',
                'status'            => 'approved',
                'created_by'        => $user->id,
                'approved_by'       => $user->id,
                'approved_at'       => now(),
                'approval_notes'    => 'Reversal by ' . ($user->first_name ?? $user->email),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('adjustment_reversed', null, [
                    'original_adjustment_id' => $original->id,
                    'reversal_id'            => $reversal->id,
                    'inventory_item_id'      => $item->id,
                    'reverse_change'         => $reverseChange,
                    'quantity_before'        => $quantityBefore,
                    'quantity_after'         => $item->quantity_on_hand,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => "Adjustment #{$original->id} reversed successfully.",
                'reversal' => $this->formatAdjustment($reversal->fresh()->load([
                    'inventoryItem.product.translations',
                    'inventoryItem.variant',
                    'inventoryItem.outlet',
                    'createdBy:id,first_name,last_name,email',
                    'approvedBy:id,first_name,last_name,email',
                ])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Reversal failed.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/adjustments/reason-codes
    // =========================================================================

    public function reasonCodes()
    {
        return response()->json(['data' => self::REASON_CODES]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function userName($user): string
    {
        if (!$user) return '-';
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $name ?: ($user->email ?? "User #{$user->id}");
    }

    private function formatAdjustment(InventoryTransaction $t, bool $withDetail = false): array
    {
        $item = $t->inventoryItem;

        return [
            'id'               => $t->id,
            'transaction_type' => $t->transaction_type,
            'reason_code'      => $t->reason_code ?? $t->transaction_type,
            'reason_label'     => self::REASON_CODES[$t->reason_code ?? $t->transaction_type]['label'] ?? ucfirst($t->transaction_type),
            'reference_number' => $t->reference_number ?? null,
            'quantity_change'  => (int) $t->quantity_change,
            'quantity_before'  => (int) $t->quantity_before,
            'quantity_after'   => (int) $t->quantity_after,
            'notes'            => $t->notes,
            'status'           => $t->status ?? 'approved',
            'approval_notes'   => $t->approval_notes ?? null,
            'created_at'       => $t->created_at,
            'approved_at'      => $t->approved_at ?? null,
            'created_by'       => $t->createdBy  ? ['id' => $t->createdBy->id,  'name' => $this->userName($t->createdBy)]  : null,
            'approved_by'      => $t->approvedBy ? ['id' => $t->approvedBy->id, 'name' => $this->userName($t->approvedBy)] : null,
            'inventory_item'   => $item ? [
                'id'           => $item->id,
                'product'      => $item->product ? [
                    'id'        => $item->product->id,
                    'sku'       => $item->product->sku,
                    'name'      => $item->product->translations?->first()?->name ?? $item->product->sku,
                    'image_url' => $item->product->images?->first()?->image_url,
                ] : null,
                'variant'      => $item->variant ? [
                    'id'           => $item->variant->id,
                    'sku'          => $item->variant->sku,
                    'variant_name' => $item->variant->variant_name,
                ] : null,
                'outlet'       => $item->outlet ? [
                    'id'   => $item->outlet->id,
                    'name' => $item->outlet->name,
                ] : null,
                'quantity_on_hand' => (int) $item->quantity_on_hand,
            ] : null,
        ];
    }
}