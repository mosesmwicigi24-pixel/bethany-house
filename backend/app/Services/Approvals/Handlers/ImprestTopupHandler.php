<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\ImprestTopupRequest;
use App\Models\User;
use App\Notifications\ImprestNotification;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Imprest top-ups: finance ≤ 100,000 · + super admin above (KES). Approval
 * says the money MAY go; the super admin still records that it went (send),
 * and the custodian what arrived (receive) — the ledger moves only then.
 */
final class ImprestTopupHandler extends ApprovalHandler
{
    public function event(): string { return 'imprest_topup'; }
    public function modelClass(): string { return ImprestTopupRequest::class; }
    public function makerCheckerAction(): string { return 'imprest_topup.approve'; }

    public function amount(Model $r, ?array $payload = null): ?array
    {
        return [(float) $r->requested_amount, 'KES'];
    }

    public function counterparty(Model $r, ?array $payload = null): ?string
    {
        return "imprest:{$r->imprest_account_id}";
    }

    public function makerIds(Model $r): array
    {
        return [$r->requested_by];
    }

    public function fingerprint(Model $r, ?array $payload = null): array
    {
        return [
            'id'                 => $r->id,
            'imprest_account_id' => $r->imprest_account_id,
            'requested_amount'   => (float) $r->requested_amount,
        ];
    }

    public function isAwaitingApproval(Model $r): bool
    {
        return $r->status === ImprestTopupRequest::PENDING && $r->requested_by !== null;
    }

    public function summary(Model $r, ?array $payload = null): array
    {
        $r->loadMissing('account:id,name,balance,float_amount');

        return [
            'title'     => 'Imprest top-up KES ' . number_format((float) $r->requested_amount, 2),
            'reference' => $r->uuid,
            'link'      => '/expenses/imprest',
            'lines'     => array_values(array_filter([
                $r->account?->name,
                $r->account ? 'Balance at request: KES ' . number_format((float) $r->balance_at_request, 2) . ' of a KES ' . number_format((float) $r->account->float_amount, 2) . ' float' : null,
                $r->reason ? "Reason: {$r->reason}" : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $r, User $finalSigner): void
    {
        ActivityLogService::log('imprest_topup_approved', $r, [
            'amount' => $r->requested_amount, 'approval_request_id' => $request->id,
        ], "Imprest top-up approved: KES {$r->requested_amount}", $finalSigner);

        // The super admin sends the money; tell him it is cleared to go —
        // unless he is the one who just signed (he is sending it now).
        if (!$finalSigner->hasRole('super_admin', 'sanctum')) {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => User::role('super_admin', 'sanctum')->where('status', 'active')->get()
                ->each(fn ($u) => $u->notify(new ImprestNotification(
                    "Imprest top-up approved — KES {$r->requested_amount}",
                    'Finance approved it. Record it as sent when the money goes.',
                    '/expenses/imprest',
                ))));
        }
    }

    public function onRejected(ApprovalRequest $request, Model $r, User $signer, string $reason): void
    {
        $r->forceFill([
            'status'         => ImprestTopupRequest::DECLINED,
            'declined_by'    => $signer->id,
            'declined_at'    => now(),
            'decline_reason' => $reason,
        ])->save();
        ActivityLogService::log('imprest_topup_declined', $r, ['reason' => $reason, 'approval_request_id' => $request->id], 'Imprest top-up declined', $signer);
        $r->requester?->notify(new ImprestNotification('Imprest top-up declined', $reason, '/expenses/imprest'));
    }

    public function onExpired(ApprovalRequest $request, Model $r): void
    {
        if ($r->status === ImprestTopupRequest::PENDING) {
            $r->forceFill(['status' => ImprestTopupRequest::EXPIRED])->save();
        }
    }

    public function reopen(Model $r, User $maker): void
    {
        if (!in_array($r->status, [ImprestTopupRequest::DECLINED, ImprestTopupRequest::EXPIRED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a declined or expired top-up can be asked for again.']);
        }
        if (ImprestTopupRequest::where('imprest_account_id', $r->imprest_account_id)
            ->whereIn('status', [ImprestTopupRequest::PENDING, ImprestTopupRequest::SENT])->exists()) {
            throw ValidationException::withMessages(['status' => 'A top-up is already on its way or waiting for a decision.']);
        }
        $r->forceFill(['status' => ImprestTopupRequest::PENDING, 'declined_by' => null, 'declined_at' => null, 'decline_reason' => null])->save();
    }
}
