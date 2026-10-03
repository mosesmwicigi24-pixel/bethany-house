<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Approvals\ApprovalHandler;
use App\Services\PaymentCorrections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Voiding a payment: the accountant (or finance) ASKS; finance ≤ 50,000
 * executes on signing · + super admin above. The request carries the reason
 * in its payload; the void happens only when the last band signs.
 */
class PaymentVoidHandler extends ApprovalHandler
{
    public function event(): string { return 'payment_void'; }
    public function modelClass(): string { return Payment::class; }
    public function makerCheckerAction(): string { return 'payment.void'; }

    public function amount(Model $p, ?array $payload = null): ?array
    {
        return [(float) $p->amount, (string) ($p->currency_code ?: 'KES')];
    }

    public function counterparty(Model $p, ?array $payload = null): ?string
    {
        $customerId = $p->order_id ? Order::withoutViewerScope()->whereKey($p->order_id)->value('customer_id') : null;

        return $customerId ? "customer:{$customerId}" : ($p->order_id ? "order:{$p->order_id}" : null);
    }

    /** Only the requester: the engine records them as the maker. */
    public function makerIds(Model $p): array
    {
        return [];
    }

    public function adoptedMaker(Model $p): ?int
    {
        return null;
    }

    public function fingerprint(Model $p, ?array $payload = null): array
    {
        return [
            'id'            => $p->id,
            'amount'        => (float) $p->amount,
            'refund_amount' => (float) $p->refund_amount,
            'status'        => $p->status,
            'order_id'      => $p->order_id,
            'payload'       => $payload ?? [],
        ];
    }

    /** A payment has no "waiting" state of its own; requests are explicit. */
    public function isAwaitingApproval(Model $p): bool
    {
        return false;
    }

    public function summary(Model $p, ?array $payload = null): array
    {
        $orderNumber = $p->order_id ? Order::withoutViewerScope()->whereKey($p->order_id)->value('order_number') : null;

        return [
            'title'     => "Void payment {$p->payment_number}",
            'reference' => $p->payment_number,
            'link'      => '/finance/transactions',
            'lines'     => array_values(array_filter([
                "{$p->currency_code} " . number_format((float) $p->amount, 2) . " by {$p->payment_method}",
                $orderNumber ? "On order {$orderNumber}" : null,
                isset($payload['reason']) ? "Reason: {$payload['reason']}" : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $p, User $finalSigner): void
    {
        PaymentCorrections::void($p, (string) ($request->payload['reason'] ?? ''), $finalSigner, $request->id);
    }

    public function onRejected(ApprovalRequest $request, Model $p, User $signer, string $reason): void
    {
        // Nothing happened to the payment; the request records the refusal.
    }

    public function onExpired(ApprovalRequest $request, Model $p): void
    {
    }

    public function reopen(Model $p, User $maker): void
    {
        if ($p->status === 'voided') {
            throw ValidationException::withMessages(['status' => 'Payment is already voided.']);
        }
    }
}
