<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Purchase orders: PM ≤ 100,000 · + finance ≤ 500,000 · + super admin above (KES). */
final class PurchaseOrderHandler extends ApprovalHandler
{
    public function event(): string { return 'purchase_order'; }
    public function modelClass(): string { return PurchaseOrder::class; }
    public function makerCheckerAction(): string { return 'purchase_order.approve'; }

    public function amount(Model $po, ?array $payload = null): ?array
    {
        return [(float) $po->total_amount, (string) ($po->currency_code ?: 'KES')];
    }

    public function counterparty(Model $po, ?array $payload = null): ?string
    {
        return $po->supplier_id ? "supplier:{$po->supplier_id}" : null;
    }

    public function makerIds(Model $po): array
    {
        return [$po->created_by, $po->submitted_by];
    }

    public function adoptedMaker(Model $po): ?int
    {
        $id = $po->submitted_by ?: $po->created_by;

        return $id ? (int) $id : null;
    }

    public function fingerprint(Model $po, ?array $payload = null): array
    {
        return [
            'id'            => $po->id,
            'supplier_id'   => $po->supplier_id,
            'currency_code' => $po->currency_code,
            'total_amount'  => (float) $po->total_amount,
            'items'         => $po->items()->orderBy('id')->get()->map(fn ($i) => [
                $i->id, $i->item_type, $i->product_id, $i->material_id, (float) $i->quantity, (float) $i->unit_price,
            ])->all(),
        ];
    }

    public function isAwaitingApproval(Model $po): bool
    {
        return $po->status === 'pending_approval';
    }

    public function summary(Model $po, ?array $payload = null): array
    {
        $po->loadMissing(['supplier:id,name', 'items']);

        return [
            'title'     => "Purchase order {$po->po_number}",
            'reference' => $po->po_number,
            'link'      => "/procurement/purchase-orders/{$po->id}",
            'lines'     => array_values(array_filter([
                'Supplier: ' . ($po->supplier?->name ?? '—'),
                "Total: {$po->currency_code} " . number_format((float) $po->total_amount, 2),
                ...$po->items->take(8)->map(fn ($i) => ($i->description ?: 'Item') . ' × ' . rtrim(rtrim(number_format((float) $i->quantity, 3), '0'), '.')
                    . " @ " . number_format((float) $i->unit_price, 2))->all(),
                $po->items->count() > 8 ? '… and ' . ($po->items->count() - 8) . ' more lines' : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $po, User $finalSigner): void
    {
        $po->update([
            'status'      => 'approved',
            'approved_by' => $finalSigner->id,
            'approved_at' => now(),
        ]);

        ActivityLogService::log('approved', $po, [
            'approved_by'         => $finalSigner->id,
            'approval_request_id' => $request->id,
        ], "PO {$po->po_number} approved by {$finalSigner->first_name} {$finalSigner->last_name}", $finalSigner);

        NotificationService::purchaseOrderStatusChanged($po->id, $po->po_number, 'pending_approval', 'approved', $po->created_by);
    }

    public function onRejected(ApprovalRequest $request, Model $po, User $signer, string $reason): void
    {
        $po->update([
            'status'      => 'draft',           // back to draft so it can be revised and resubmitted
            'approved_by' => null,
            'approved_at' => null,
            'notes'       => "REJECTED: {$reason}\n\n" . ($po->notes ?? ''),
        ]);

        ActivityLogService::log('rejected', $po, [
            'rejected_by'         => $signer->id,
            'reason'              => $reason,
            'approval_request_id' => $request->id,
        ], "PO {$po->po_number} rejected by {$signer->first_name} {$signer->last_name}: {$reason}", $signer);

        NotificationService::purchaseOrderStatusChanged($po->id, $po->po_number, 'pending_approval', 'draft', $po->created_by);
    }

    public function onExpired(ApprovalRequest $request, Model $po): void
    {
        if ($po->status !== 'pending_approval') {
            return;
        }
        $po->update([
            'status' => 'draft',
            'notes'  => "EXPIRED: no approval decision within 72 hours — returned to draft.\n\n" . ($po->notes ?? ''),
        ]);
        NotificationService::purchaseOrderStatusChanged($po->id, $po->po_number, 'pending_approval', 'draft', $po->created_by);
    }

    public function reopen(Model $po, User $maker): void
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft purchase order can be resubmitted.']);
        }
        if ($po->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Cannot submit a purchase order with no items.']);
        }
        $po->update(['status' => 'pending_approval', 'submitted_by' => $maker->id, 'submitted_at' => now()]);
    }
}
