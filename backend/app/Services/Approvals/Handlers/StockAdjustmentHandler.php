<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use App\Support\CostBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Stock adjustments, valued at |quantity| × the product's KES book cost
 * (App\Support\CostBasis). A product with no cost on the book cannot be
 * valued, so it needs every band — the same honesty as a missing FX rate.
 */
final class StockAdjustmentHandler extends ApprovalHandler
{
    public function event(): string { return 'stock_adjustment'; }
    public function modelClass(): string { return InventoryTransaction::class; }
    public function makerCheckerAction(): string { return 'stock_adjustment.approve'; }

    /** |qty| × unit cost in KES, or null when the product has no cost on the book. */
    public static function valueKes(int $inventoryItemId, int $quantityChange): ?float
    {
        $item = \App\Models\InventoryItem::find($inventoryItemId);
        if (!$item) {
            return null;
        }
        $cost = CostBasis::unitCostFor((int) $item->product_id, $item->product_variant_id ? (int) $item->product_variant_id : null);

        return $cost === null ? null : round(abs($quantityChange) * $cost, 2);
    }

    public function amount(Model $t, ?array $payload = null): ?array
    {
        return [self::valueKes((int) $t->inventory_item_id, (int) $t->quantity_change), 'KES'];
    }

    public function counterparty(Model $t, ?array $payload = null): ?string
    {
        return "inventory_item:{$t->inventory_item_id}";
    }

    public function makerIds(Model $t): array
    {
        return [$t->created_by];
    }

    /** The outlet of the stock row it moves (as StockAdjustmentsController::scoped()). */
    public function outletScope(Model $t): ?array
    {
        $outletId = \Illuminate\Support\Facades\DB::table('inventory_items')->where('id', $t->inventory_item_id)->value('outlet_id');

        return ['inventory.view', [$outletId === null ? null : (int) $outletId]];
    }

    public function fingerprint(Model $t, ?array $payload = null): array
    {
        return [
            'id'                => $t->id,
            'inventory_item_id' => $t->inventory_item_id,
            'quantity_change'   => (int) $t->quantity_change,
            'reason_code'       => $t->reason_code,
        ];
    }

    public function isAwaitingApproval(Model $t): bool
    {
        return $t->status === 'pending_approval';
    }

    public function summary(Model $t, ?array $payload = null): array
    {
        $t->loadMissing(['inventoryItem.product.translations', 'inventoryItem.variant', 'inventoryItem.outlet']);
        $item = $t->inventoryItem;
        $name = $item?->product?->translations?->first()?->name ?? $item?->product?->sku ?? 'Unknown product';
        $sku  = $item?->variant?->sku ?? $item?->product?->sku ?? '';
        $sign = $t->quantity_change > 0 ? '+' : '';

        return [
            'title'     => "Stock adjustment {$sign}{$t->quantity_change} {$name}",
            'reference' => "ADJ-{$t->id}",
            'link'      => "/inventory/adjustments/{$t->id}",
            'lines'     => array_values(array_filter([
                $sku ? "SKU {$sku}" : null,
                'Outlet: ' . ($item?->outlet?->name ?? 'Warehouse'),
                'Reason: ' . ($t->reason_code ?? $t->transaction_type),
                $t->notes ? "Notes: {$t->notes}" : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $t, User $finalSigner): void
    {
        $item = $t->inventoryItem()->lockForUpdate()->first();
        if ($t->quantity_change < 0 && $item->quantity_on_hand + $t->quantity_change < 0) {
            throw new HttpResponseException(response()->json([
                'message' => "Cannot approve - stock has changed. Available: {$item->quantity_on_hand}, change: {$t->quantity_change}.",
                'code'    => 'STOCK_CHANGED',
            ], 422));
        }

        $before = (int) $item->quantity_on_hand;
        $item->increment('quantity_on_hand', $t->quantity_change);
        $item->refresh();

        $t->update([
            'status'          => 'approved',
            'quantity_before' => $before,
            'quantity_after'  => (int) $item->quantity_on_hand,
            'approved_by'     => $finalSigner->id,
            'approved_at'     => now(),
            'approval_notes'  => $request->signatures()->latest('id')->value('reason'),
        ]);

        $sign = $t->quantity_change > 0 ? '+' : '';
        ActivityLogService::log('adjustment_approved', null, [
            'transaction_id'      => $t->id,
            'quantity_before'     => $before,
            'quantity_change'     => $t->quantity_change,
            'quantity_after'      => (int) $item->quantity_on_hand,
            'approved_by'         => $finalSigner->id,
            'approval_request_id' => $request->id,
        ], "Adjustment approved: #{$t->id} {$sign}{$t->quantity_change} (was {$before}, now {$item->quantity_on_hand})", $finalSigner);
    }

    public function onRejected(ApprovalRequest $request, Model $t, User $signer, string $reason): void
    {
        $t->update([
            'status'         => 'rejected',
            'approved_by'    => $signer->id,
            'approved_at'    => now(),
            'approval_notes' => $reason,
        ]);

        ActivityLogService::log('adjustment_rejected', null, [
            'transaction_id'      => $t->id,
            'reason'              => $reason,
            'rejected_by'         => $signer->id,
            'approval_request_id' => $request->id,
        ], "Adjustment #{$t->id} rejected: {$reason}", $signer);
    }

    public function onExpired(ApprovalRequest $request, Model $t): void
    {
        if ($t->status === 'pending_approval') {
            // Back to the maker: not applied, not rejected; resubmit to try again.
            $t->update(['status' => 'returned', 'approval_notes' => 'No approval decision within 72 hours.']);
        }
    }

    public function reopen(Model $t, User $maker): void
    {
        if (!in_array($t->status, ['rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a rejected or returned adjustment can be resubmitted.']);
        }
        $t->update(['status' => 'pending_approval', 'approved_by' => null, 'approved_at' => null, 'approval_notes' => null]);
    }
}
