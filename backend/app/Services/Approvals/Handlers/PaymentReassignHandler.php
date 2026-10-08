<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentCorrections;
use Illuminate\Database\Eloquent\Model;

/** Moving a payment to another order: same bands as a void, on its own event. */
final class PaymentReassignHandler extends PaymentVoidHandler
{
    public function event(): string { return 'payment_reassign'; }
    public function makerCheckerAction(): string { return 'payment.reassign'; }

    public function summary(Model $p, ?array $payload = null): array
    {
        $from = $p->order_id ? Order::withoutViewerScope()->whereKey($p->order_id)->value('order_number') : null;
        $to   = isset($payload['order_id']) ? Order::withoutViewerScope()->whereKey($payload['order_id'])->value('order_number') : null;

        return [
            'title'     => "Move payment {$p->payment_number}",
            'reference' => $p->payment_number,
            'link'      => '/finance/transactions',
            'lines'     => array_values(array_filter([
                "{$p->currency_code} " . number_format((float) $p->amount, 2) . " by {$p->payment_method}",
                'From ' . ($from ?? '—') . ' to ' . ($to ?? ('order #' . ($payload['order_id'] ?? '?'))),
                isset($payload['reason']) ? "Reason: {$payload['reason']}" : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $p, User $finalSigner): void
    {
        PaymentCorrections::reassign($p, (int) ($request->payload['order_id'] ?? 0), (string) ($request->payload['reason'] ?? ''), $finalSigner, $request->id);
    }
}
