<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\Order;
use App\Models\PosRefundRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalHandler;
use App\Services\Pos\TillReversals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A till refund (POS return), asked for by a clerk or outlet manager
 * (Phase 4B part 2). The record is the PosRefundRequest — what was asked —
 * and the refund itself is written as a NEW order_returns row only when the
 * last band signs (TillReversals::executeRefund). Bands (approval_thresholds
 * pos_refund): outlet manager ≤ 5,000 · finance ≤ 50,000 · super admin above.
 *
 * Anti-splitting is keyed on the sale: three partial refunds of one sale by
 * the same person within 24 hours are banded on their sum.
 */
class PosRefundHandler extends ApprovalHandler
{
    public function event(): string { return TillReversals::REFUND; }
    public function modelClass(): string { return PosRefundRequest::class; }
    public function makerCheckerAction(): string { return 'pos.refund'; }

    public function amount(Model $r, ?array $payload = null): ?array
    {
        return [(float) $r->refund_amount, (string) ($r->currency_code ?: 'KES')];
    }

    public function counterparty(Model $r, ?array $payload = null): ?string
    {
        return "order:{$r->order_id}";
    }

    public function makerIds(Model $r): array
    {
        return array_values(array_filter([$r->requested_by]));
    }

    public function fingerprint(Model $r, ?array $payload = null): array
    {
        return [
            'id'            => $r->id,
            'order_id'      => $r->order_id,
            'status'        => $r->status,
            'items'         => $r->items,
            'refund_amount' => (float) $r->refund_amount,
            'currency_code' => $r->currency_code,
            'refund_method' => $r->refund_method,
            'reason'        => $r->reason,
        ];
    }

    public function isAwaitingApproval(Model $r): bool
    {
        return $r->status === PosRefundRequest::PENDING;
    }

    public function summary(Model $r, ?array $payload = null): array
    {
        $order  = Order::withoutViewerScope()->whereKey($r->order_id)->first(['id', 'order_number', 'outlet_id']);
        $outlet = $order?->outlet_id ? \App\Models\Outlet::whereKey($order->outlet_id)->value('name') : null;
        $units  = (int) collect($r->items)->sum('quantity');

        return [
            'title'     => 'Refund on till sale ' . ($order?->order_number ?? "#{$r->order_id}"),
            'reference' => $order?->order_number,
            'link'      => '/pos',
            'lines'     => array_values(array_filter([
                ($r->currency_code ?: 'KES') . ' ' . number_format((float) $r->refund_amount, 2) . " by {$r->refund_method}" . ($outlet ? " at {$outlet}" : ''),
                "{$units} unit" . ($units === 1 ? '' : 's') . ' returned',
                "Reason: {$r->reason}",
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $r, User $finalSigner): void
    {
        app(TillReversals::class)->executeRefund($request, $r, $finalSigner);
    }

    public function onRejected(ApprovalRequest $request, Model $r, User $signer, string $reason): void
    {
        $r->update(['status' => PosRefundRequest::REJECTED, 'decided_by' => $signer->id, 'decided_at' => now()]);
    }

    public function onExpired(ApprovalRequest $request, Model $r): void
    {
        $r->update(['status' => PosRefundRequest::EXPIRED, 'decided_at' => now()]);
    }

    /** Back to pending for a new version, if the lines can still be refunded. */
    public function reopen(Model $r, User $maker): void
    {
        if (!in_array($r->status, [PosRefundRequest::REJECTED, PosRefundRequest::EXPIRED], true)) {
            throw ValidationException::withMessages(['status' => "This refund request is {$r->status}."]);
        }
        $order = Order::withoutViewerScope()->with('items')->findOrFail($r->order_id);
        app(TillReversals::class)->priceRefund($order, array_map(fn ($l) => [
            'variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
        ], $r->items), $r->id);

        $r->update(['status' => PosRefundRequest::PENDING, 'decided_by' => null, 'decided_at' => null]);
    }
}
