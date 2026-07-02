<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use App\Services\ActivityLogService;

class StockTransfersController extends Controller
{
    // =========================================================================
    // GET /api/v1/admin/inventory/transfers
    // =========================================================================

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 25), 100);

        $query = InventoryTransfer::with([
            'fromOutlet:id,name,code',
            'toOutlet:id,name,code',
            'items.product:id,sku',
            'items.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'items.variant:id,sku,variant_name',
            'requestedBy:id,first_name,last_name,email',
            'approvedBy:id,first_name,last_name,email',
        ])->withCount('items');

        if ($request->filled('status'))         $query->where('status', $request->status);
        if ($request->filled('from_outlet_id')) $query->where('from_outlet_id', $request->from_outlet_id);
        if ($request->filled('to_outlet_id'))   $query->where('to_outlet_id', $request->to_outlet_id);
        if ($request->filled('search'))         $query->where('transfer_number', 'ILIKE', "%{$request->search}%");

        $transfers = $query->orderByDesc('created_at')->paginate($perPage);

        $stats = [
            'total'      => InventoryTransfer::count(),
            'pending'    => InventoryTransfer::where('status', 'pending')->count(),
            'approved'   => InventoryTransfer::where('status', 'approved')->count(),
            'in_transit' => InventoryTransfer::where('status', 'in_transit')->count(),
            'completed'  => InventoryTransfer::where('status', 'completed')->count(),
            'cancelled'  => InventoryTransfer::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'data'  => collect($transfers->items())->map(fn ($t) => $this->formatTransfer($t)),
            'meta'  => [
                'current_page' => $transfers->currentPage(),
                'last_page'    => $transfers->lastPage(),
                'total'        => $transfers->total(),
                'from'         => $transfers->firstItem(),
                'to'           => $transfers->lastItem(),
            ],
            'stats' => $stats,
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/transfers/{id}
    // =========================================================================

    public function show($id)
    {
        $transfer = InventoryTransfer::with([
            'fromOutlet:id,name,code',
            'toOutlet:id,name,code',
            'items.product:id,sku',
            'items.product.translations' => fn ($q) => $q->where('language_code', 'en'),
            'items.product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'items.variant:id,sku,variant_name,attributes',
            'requestedBy:id,first_name,last_name,email',
            'approvedBy:id,first_name,last_name,email',
            'completedBy:id,first_name,last_name,email',
        ])->findOrFail($id);

        // Enrich with current stock at source outlet
        $transfer->items->each(function ($item) use ($transfer) {
            $item->source_stock = InventoryItem::where('product_id', $item->product_id)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('outlet_id', $transfer->from_outlet_id)
                ->value('quantity_on_hand') ?? 0;
        });

        return response()->json(['transfer' => $this->formatTransfer($transfer, true)]);
    }

    // =========================================================================
    // POST /api/v1/admin/inventory/transfers
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_outlet_id'             => ['required', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\Outlet::class, 'id')],
            'to_outlet_id'               => ['required', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\Outlet::class, 'id'), 'different:from_outlet_id'],
            'notes'                      => 'nullable|string|max:1000',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => ['required', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\Product::class, 'id')],
            'items.*.product_variant_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\ProductVariant::class, 'id')],
            'items.*.quantity_requested' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $createData = [
                'from_outlet_id' => $validated['from_outlet_id'],
                'to_outlet_id'   => $validated['to_outlet_id'],
                'status'         => 'pending',
                'notes'          => $validated['notes'] ?? null,
                'transfer_date'  => now()->toDateString(), // required NOT NULL
            ];

            $itCols = \Illuminate\Support\Facades\Schema::getColumnListing('inventory_transfers');
            if (in_array('requested_by', $itCols)) $createData['requested_by'] = auth()->id();
            if (in_array('approved_by',  $itCols)) $createData['approved_by']  = null;
            if (in_array('created_by',   $itCols)) $createData['created_by']   = auth()->id();
            if (in_array('requested_at', $itCols)) $createData['requested_at'] = now();

            $transfer = InventoryTransfer::create($createData);

            foreach ($validated['items'] as $item) {
                InventoryTransferItem::create([
                    'transfer_id'          => $transfer->id,
                    'product_id'           => $item['product_id'],
                    'product_variant_id'   => $item['product_variant_id'] ?? null,
                    'quantity_requested'   => $item['quantity_requested'],
                    'quantity_received' => 0,
                ]);
            }

            DB::commit();

            // ── Audit log ─────────────────────────────────────────────────────
            $fromName = \App\Models\Outlet::find($validated['from_outlet_id'])?->name ?? "Outlet #{$validated['from_outlet_id']}";
            $toName   = \App\Models\Outlet::find($validated['to_outlet_id'])?->name   ?? "Outlet #{$validated['to_outlet_id']}";
            ActivityLogService::log('transfer_created', $transfer, [
                'transfer_number' => $transfer->transfer_number,
                'from_outlet'     => $fromName,
                'to_outlet'       => $toName,
                'items_count'     => count($validated['items']),
                'notes'           => $validated['notes'] ?? null,
            ], "Transfer {$transfer->transfer_number}: {$fromName} → {$toName} (" . count($validated['items']) . " item(s))");

            // ── Notification ──────────────────────────────────────────────────
            NotificationService::stockTransferCreated(
                $transfer->id,
                $transfer->transfer_number,
                $fromName,
                $toName,
                count($validated['items'])
            );

            // Reload with relations separately so any relation error is surfaced cleanly
            try {
                $loaded = $transfer->fresh()->load([
                    'fromOutlet:id,name',
                    'toOutlet:id,name',
                    'items.product:id,sku',
                    'items.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
                    'items.variant:id,sku,variant_name',
                    'requestedBy:id,first_name,last_name,email',
                ]);
                $formatted = $this->formatTransfer($loaded);
            } catch (\Exception $re) {
                // Relations failed - return minimal response so transfer isn't lost
                $formatted = ['id' => $transfer->id, 'transfer_number' => $transfer->transfer_number, 'status' => 'pending'];
            }

            return response()->json([
                'message'  => "Transfer {$transfer->transfer_number} created.",
                'transfer' => $formatted,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            // Return full error detail to help debug column/table issues
            return response()->json([
                'message' => 'Failed to create transfer.',
                'error'   => $e->getMessage(),
                'hint'    => 'Check column names in inventory_transfers and inventory_transfer_items tables.',
            ], 500);
        }
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/transfers/{id}/approve
    // =========================================================================

    public function approve($id)
    {
        $transfer = InventoryTransfer::where('status', 'pending')->findOrFail($id);
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('inventory_transfers');
        $updateData = ['status' => 'approved'];
        if (in_array('approved_by', $cols)) $updateData['approved_by'] = auth()->id();
        if (in_array('approved_at', $cols)) $updateData['approved_at'] = now();
        $transfer->update($updateData);
        return response()->json(['message' => "Transfer {$transfer->transfer_number} approved."]);
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/transfers/{id}/dispatch
    // Stock leaves source outlet - status becomes in_transit.
    // =========================================================================

    public function dispatch(Request $request, $id)
    {
        $transfer = InventoryTransfer::with('items')->where('status', 'approved')->findOrFail($id);

        $validated = $request->validate([
            'items'                            => 'required|array',
            'items.*.id'                       => ['required', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\InventoryTransferItem::class, 'id')],
            'items.*.quantity_received'     => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $itemData) {
                $item = $transfer->items->firstWhere('id', $itemData['id']);
                if (!$item) continue;

                $qty = (int) $itemData['quantity_received'];
                if ($qty <= 0) continue;

                $sourceStock = InventoryItem::where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('outlet_id', $transfer->from_outlet_id)
                    ->first();

                if (!$sourceStock || $sourceStock->quantity_on_hand < $qty) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock at source outlet for item #{$item->id}. Available: " . ($sourceStock?->quantity_on_hand ?? 0),
                    ], 422);
                }

                $before = $sourceStock->quantity_on_hand;
                $sourceStock->decrement('quantity_on_hand', $qty);

                InventoryTransaction::create([
                    'inventory_item_id' => $sourceStock->id,
                    'transaction_type'  => 'transfer_out',
                    'reference_type'    => 'inventory_transfer',
                    'reference_id'      => $transfer->id,
                    'quantity_change'   => -$qty,
                    'quantity_before'   => $before,
                    'quantity_after'    => $before - $qty,
                    'notes'             => "Dispatched on transfer {$transfer->transfer_number}",
                    'status'            => 'approved',
                    'created_by'        => auth()->id(),
                ]);

                $item->update(['quantity_received' => $qty]);
            }

            $transfer->update(['status' => 'in_transit']);
            DB::commit();

            ActivityLogService::log('transfer_dispatched', $transfer, [
                'dispatched_by' => auth()->id(),
                'items_count'   => $transfer->items->count(),
            ], "Transfer {$transfer->transfer_number} dispatched - in transit");

            return response()->json(['message' => "Transfer {$transfer->transfer_number} dispatched - in transit."]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Dispatch failed.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/transfers/{id}/receive
    // Stock arrives at destination - completes the transfer.
    // =========================================================================

    public function receive($id)
    {
        $transfer = InventoryTransfer::with('items')->where('status', 'in_transit')->findOrFail($id);

        DB::beginTransaction();
        try {
            foreach ($transfer->items as $item) {
                $qty = $item->quantity_received;
                if ($qty <= 0) continue;

                $destStock = InventoryItem::firstOrCreate(
                    ['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'outlet_id' => $transfer->to_outlet_id],
                    ['quantity_on_hand' => 0, 'quantity_reserved' => 0]
                );

                $before = $destStock->quantity_on_hand;
                $destStock->increment('quantity_on_hand', $qty);

                InventoryTransaction::create([
                    'inventory_item_id' => $destStock->id,
                    'transaction_type'  => 'transfer_in',
                    'reference_type'    => 'inventory_transfer',
                    'reference_id'      => $transfer->id,
                    'quantity_change'   => $qty,
                    'quantity_before'   => $before,
                    'quantity_after'    => $before + $qty,
                    'notes'             => "Received on transfer {$transfer->transfer_number}",
                    'status'            => 'approved',
                    'created_by'        => auth()->id(),
                ]);
            }

            $cols = \Illuminate\Support\Facades\Schema::getColumnListing('inventory_transfers');
            $doneData = ['status' => 'completed'];
            if (in_array('completed_by', $cols)) $doneData['completed_by'] = auth()->id();
            if (in_array('completed_at', $cols)) $doneData['completed_at'] = now();
            $transfer->update($doneData);
            DB::commit();

            $transfer->loadMissing(['fromOutlet:id,name', 'toOutlet:id,name']);
            $fromName = $transfer->fromOutlet?->name ?? 'Unknown';
            $toName   = $transfer->toOutlet?->name   ?? 'Unknown';

            ActivityLogService::log('transfer_received', $transfer, [
                'received_by'  => auth()->id(),
                'from_outlet'  => $fromName,
                'to_outlet'    => $toName,
            ], "Transfer {$transfer->transfer_number} received at {$toName}");

            NotificationService::stockTransferCompleted(
                $transfer->id,
                $transfer->transfer_number,
                $fromName,
                $toName
            );

            return response()->json(['message' => "Transfer {$transfer->transfer_number} received and completed."]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Receive failed.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/transfers/{id}/cancel
    // =========================================================================

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $transfer = InventoryTransfer::whereIn('status', ['pending', 'approved'])->findOrFail($id);

        $transfer->update([
            'status' => 'cancelled',
            'notes'  => trim(($transfer->notes ?? '') . "\n[Cancelled: " . ($request->reason ?? 'No reason given') . "]"),
        ]);

        ActivityLogService::log('transfer_cancelled', $transfer, [
            'reason'       => $request->reason ?? 'No reason given',
            'cancelled_by' => auth()->id(),
        ], "Transfer {$transfer->transfer_number} cancelled");

        return response()->json(['message' => "Transfer {$transfer->transfer_number} cancelled."]);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/transfers/{id}/audit-log
    // =========================================================================

    public function auditLog($id)
    {
        $transfer = InventoryTransfer::findOrFail($id);

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where('al.subject_type', \App\Models\InventoryTransfer::class)
            ->where('al.subject_id', $transfer->id)
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
    // PRIVATE HELPERS
    // =========================================================================

    private function userName($user): string
    {
        if (!$user) return '-';
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $name ?: ($user->email ?? "User #{$user->id}");
    }

    private function formatTransfer(InventoryTransfer $t, bool $withDetail = false): array
    {
        return [
            'id'              => $t->id,
            'transfer_number' => $t->transfer_number,
            'status'          => $t->status,
            'notes'           => $t->notes,
            'items_count'     => $t->items_count ?? $t->items->count(),
            'requested_at'    => $t->requested_at   ?? $t->created_at,
            'approved_at'     => $t->approved_at    ?? null,
            'completed_at'    => $t->completed_at   ?? null,
            'created_at'      => $t->created_at,
            'from_outlet'     => $t->fromOutlet ? ['id' => $t->fromOutlet->id, 'name' => $t->fromOutlet->name] : null,
            'to_outlet'       => $t->toOutlet   ? ['id' => $t->toOutlet->id,   'name' => $t->toOutlet->name]   : null,
            'requested_by'    => ($t->requestedBy ?? null) ? ['id' => $t->requestedBy->id, 'name' => $this->userName($t->requestedBy)] : null,
            'approved_by'     => ($t->approvedBy  ?? null) ? ['id' => $t->approvedBy->id,  'name' => $this->userName($t->approvedBy)]  : null,
            'completed_by'    => ($t->completedBy ?? null) ? ['id' => $t->completedBy->id, 'name' => $this->userName($t->completedBy)] : null,
            'items' => $withDetail ? $t->items->map(fn ($item) => [
                'id'                   => $item->id,
                'product_id'           => $item->product_id,
                'product_variant_id'   => $item->product_variant_id,
                'quantity_requested'   => $item->quantity_requested,
                'quantity_received' => $item->quantity_received,
                'source_stock'         => $item->source_stock ?? null,
                'product' => $item->product ? [
                    'id'        => $item->product->id,
                    'sku'       => $item->product->sku,
                    'name'      => $item->product->translations?->first()?->name ?? $item->product->sku,
                    'image_url' => $item->product->images?->first()?->image_url,
                ] : null,
                'variant' => $item->variant ? [
                    'id'           => $item->variant->id,
                    'sku'          => $item->variant->sku,
                    'variant_name' => $item->variant->variant_name,
                ] : null,
            ])->values() : [],
        ];
    }
}