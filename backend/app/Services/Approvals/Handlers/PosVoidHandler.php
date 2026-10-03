<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Approvals\ApprovalHandler;
use App\Services\Pos\TillReversals;
use Illuminate\Database\Eloquent\Model;

/**
 * Voiding a till sale before its till is closed (Phase 4B part 2).
 *
 * The clerk (or outlet manager) ASKS — POST /admin/pos/sales/{id}/void now
 * raises this request instead of voiding. Bands (approval_thresholds
 * pos_void): outlet manager ≤ 20,000 · finance ≤ 100,000 · super admin above,
 * cumulative. The void happens only when the last band signs, and only if the
 * sale's till is still open then — App\Services\Pos\TillReversals::executeVoid.
 * The reason travels in the payload, so it is part of what was signed.
 */
class PosVoidHandler extends ApprovalHandler
{
    public function event(): string { return TillReversals::VOID; }
    public function modelClass(): string { return Order::class; }
    public function makerCheckerAction(): string { return 'pos.void'; }

    public function amount(Model $o, ?array $payload = null): ?array
    {
        return [(float) $o->total_amount, (string) ($o->currency_code ?: 'KES')];
    }

    /** Keyed on the sale: one sale is one void, so nothing accumulates across sales. */
    public function counterparty(Model $o, ?array $payload = null): ?string
    {
        return "order:{$o->id}";
    }

    /** Only the requester: the engine records them as the maker. */
    public function makerIds(Model $o): array
    {
        return [];
    }

    public function adoptedMaker(Model $o): ?int
    {
        return null;
    }

    public function fingerprint(Model $o, ?array $payload = null): array
    {
        return [
            'id'             => $o->id,
            'status'         => $o->status,
            'total_amount'   => (float) $o->total_amount,
            'currency_code'  => $o->currency_code,
            'payment_status' => $o->payment_status,
            'payload'        => $payload ?? [],
        ];
    }

    /** A sale has no "waiting" state of its own; void requests are explicit. */
    public function isAwaitingApproval(Model $o): bool
    {
        return false;
    }

    public function summary(Model $o, ?array $payload = null): array
    {
        $outlet = $o->outlet_id ? \App\Models\Outlet::whereKey($o->outlet_id)->value('name') : null;

        return [
            'title'     => "Void till sale {$o->order_number}",
            'reference' => $o->order_number,
            'link'      => '/pos',
            'lines'     => array_values(array_filter([
                ($o->currency_code ?: 'KES') . ' ' . number_format((float) $o->total_amount, 2) . ($outlet ? " at {$outlet}" : ''),
                isset($payload['reason']) ? "Reason: {$payload['reason']}" : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $o, User $finalSigner): void
    {
        app(TillReversals::class)->executeVoid($request, $o, $finalSigner);
    }

    public function onRejected(ApprovalRequest $request, Model $o, User $signer, string $reason): void
    {
        // Nothing happened to the sale; the request records the refusal.
    }

    public function onExpired(ApprovalRequest $request, Model $o): void
    {
    }

    /** Back for a new version only while the sale can still be voided. */
    public function reopen(Model $o, User $maker): void
    {
        app(TillReversals::class)->assertVoidable($o);
    }
}
