<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\InventoryTransfer;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Inter-outlet stock transfers: the procurement manager approves; no value band. */
final class StockTransferHandler extends ApprovalHandler
{
    public function event(): string { return 'stock_transfer'; }
    public function modelClass(): string { return InventoryTransfer::class; }
    public function makerCheckerAction(): string { return 'stock_transfer.approve'; }

    public function amount(Model $t, ?array $payload = null): ?array
    {
        return null;
    }

    public function counterparty(Model $t, ?array $payload = null): ?string
    {
        return $t->to_outlet_id ? "outlet:{$t->to_outlet_id}" : null;
    }

    public function makerIds(Model $t): array
    {
        return [$t->created_by, $t->getAttribute('requested_by')];
    }

    /** Either side (as InventoryTransfer::scopeVisibleTo()). */
    public function outletScope(Model $t): ?array
    {
        return ['inventory.view', [
            $t->from_outlet_id === null ? null : (int) $t->from_outlet_id,
            $t->to_outlet_id === null ? null : (int) $t->to_outlet_id,
        ]];
    }

    public function adoptedMaker(Model $t): ?int
    {
        $id = $t->getAttribute('requested_by') ?: $t->created_by;

        return $id ? (int) $id : null;
    }

    public function fingerprint(Model $t, ?array $payload = null): array
    {
        return [
            'id'    => $t->id,
            'from'  => $t->from_outlet_id,
            'to'    => $t->to_outlet_id,
            'items' => $t->items()->orderBy('id')->get()->map(fn ($i) => [
                $i->product_id, $i->product_variant_id, (int) $i->quantity_requested,
            ])->all(),
        ];
    }

    public function isAwaitingApproval(Model $t): bool
    {
        return $t->status === 'pending';
    }

    public function summary(Model $t, ?array $payload = null): array
    {
        $t->loadMissing(['fromOutlet:id,name', 'toOutlet:id,name', 'items']);

        return [
            'title'     => "Stock transfer {$t->transfer_number}",
            'reference' => $t->transfer_number,
            'link'      => "/inventory/transfers/{$t->id}",
            'lines'     => [
                ($t->fromOutlet?->name ?? "Outlet #{$t->from_outlet_id}") . ' → ' . ($t->toOutlet?->name ?? "Outlet #{$t->to_outlet_id}"),
                $t->items->count() . ' line(s), ' . (int) $t->items->sum('quantity_requested') . ' unit(s)',
            ],
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $t, User $finalSigner): void
    {
        $cols = Schema::getColumnListing('inventory_transfers');
        $data = ['status' => 'approved'];
        if (in_array('approved_by', $cols, true)) $data['approved_by'] = $finalSigner->id;
        if (in_array('approved_at', $cols, true)) $data['approved_at'] = now();
        $t->update($data);

        ActivityLogService::log('transfer_approved', $t, [
            'approval_request_id' => $request->id,
        ], "Transfer {$t->transfer_number} approved", $finalSigner);
    }

    public function onRejected(ApprovalRequest $request, Model $t, User $signer, string $reason): void
    {
        $t->update(['status' => 'rejected', 'notes' => "REJECTED: {$reason}\n\n" . ($t->notes ?? '')]);
        ActivityLogService::log('transfer_rejected', $t, [
            'reason' => $reason, 'approval_request_id' => $request->id,
        ], "Transfer {$t->transfer_number} rejected: {$reason}", $signer);
    }

    public function onExpired(ApprovalRequest $request, Model $t): void
    {
        if ($t->status === 'pending') {
            $t->update(['status' => 'returned', 'notes' => "EXPIRED: no approval decision within 72 hours.\n\n" . ($t->notes ?? '')]);
        }
    }

    public function reopen(Model $t, User $maker): void
    {
        if (!in_array($t->status, ['rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a rejected or returned transfer can be resubmitted.']);
        }
        $t->update(['status' => 'pending']);
    }
}
