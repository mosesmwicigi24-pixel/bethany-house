<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Models\Order;
use App\Models\PosRefundRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Auth\TerminalPin;
use App\Services\Pos\TillApproverPin;
use App\Services\Pos\TillReversals;
use App\Support\MakerChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approving a till void or refund on the clerk's own terminal (Phase 4B part 2).
 *
 *   GET  /admin/pos/sales/{id}/reversals          the void/refund requests waiting on a sale
 *   GET  /admin/pos/approvals/{id}/approvers      who could sign it now, at this till
 *   POST /admin/pos/approvals/{id}/pin-sign       {approver_id, pin, decision?, reason?, version?}
 *
 * The person signed in at the terminal is the clerk; the approver steps up and
 * enters THEIR terminal PIN. The signature is the approver's — the engine
 * checks their band, maker ≠ checker, one signature per person — and is
 * recorded with the requester, the approver and the terminal session
 * (approval_terminal_signatures). An approver who is not at the till signs
 * from the Approvals inbox instead (/admin/approvals/{id}/sign).
 */
class PosTillApprovalController extends Controller
{
    private const EVENTS = [TillReversals::VOID, TillReversals::REFUND];

    public function __construct(
        private ApprovalEngine $engine,
        private TillReversals $reversals,
        private TillApproverPin $approverPin,
        private TerminalPin $pins,
    ) {}

    public function forSale(Request $request, int $id): JsonResponse
    {
        $order = Order::where('order_type', 'pos')->findOrFail($id);
        $this->authoriseOutlet($request->user(), (int) $order->outlet_id);

        return response()->json([
            'sale_id'     => $order->id,
            'pending'     => $this->reversals->pendingForOrder($order->id),
            'till_closed' => $this->reversals->tillClosed($order),
        ]);
    }

    public function approvers(Request $request, int $id): JsonResponse
    {
        [$approval, $order] = $this->load($id);
        $this->authoriseOutlet($request->user(), (int) $order->outlet_id);

        $candidates = User::where('status', 'active')
            ->whereKeyNot((int) $approval->maker_id)
            ->get()
            ->filter(fn (User $u) => $this->engine->canSignNow($u, $approval) && $this->mayApproveAtOutlet($u, (int) $order->outlet_id))
            ->values();

        return response()->json([
            'data' => $candidates->map(fn (User $u) => [
                'id'      => $u->id,
                'name'    => trim("{$u->first_name} {$u->last_name}") ?: $u->email,
                'pin_set' => $this->pins->isSet($u),
            ])->values(),
        ]);
    }

    public function pinSign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approver_id' => 'required|integer',
            'pin'         => 'required|string|max:12',
            'decision'    => 'nullable|in:approve,reject',
            'reason'      => 'required_if:decision,reject|nullable|string|min:3|max:1000',
            'version'     => 'nullable|integer|min:1',
        ]);
        $terminalUser = $request->user();
        $decision     = ($data['decision'] ?? 'approve') === 'reject' ? ApprovalSignature::REJECTED : ApprovalSignature::APPROVED;

        [$approval, $order, $record] = $this->load($id);
        $this->authoriseOutlet($terminalUser, (int) $order->outlet_id);

        $approver = User::where('status', 'active')->find((int) $data['approver_id']);
        if (!$approver) {
            return response()->json(['message' => 'That approver is not an active member of staff.', 'code' => 'APPROVER_UNKNOWN'], 422);
        }

        // The requester's own PIN never signs their own request — refused (and
        // audited) before the PIN is even checked.
        if ($decision === ApprovalSignature::APPROVED) {
            MakerChecker::assertNotMaker($approver, $this->engine->handler($approval->event)->makerCheckerAction(), $record,
                $approval->maker_id, ...$this->engine->handler($approval->event)->makerIds($record));
        }

        $this->approverPin->check($terminalUser, $approver, (string) $data['pin'], $approval);

        if (!$this->mayApproveAtOutlet($approver, (int) $order->outlet_id)) {
            return response()->json([
                'message' => 'This approver is not assigned to this outlet. They can sign from their Approvals inbox if their band allows.',
                'code'    => 'APPROVER_NOT_AT_OUTLET',
            ], 403);
        }

        $signed = $this->engine->sign(
            $approval, $approver, $decision, $data['reason'] ?? null,
            (int) $approval->approvable_id, isset($data['version']) ? (int) $data['version'] : null,
        );

        $signature = ApprovalSignature::where('approval_request_id', $signed->id)
            ->where('signer_id', $approver->id)->where('decision', $decision)
            ->orderByDesc('id')->first();

        DB::table('approval_terminal_signatures')->insert([
            'approval_request_id'   => $signed->id,
            'approval_signature_id' => $signature?->id,
            'decision'              => $decision,
            'requester_id'          => $signed->maker_id,
            'approver_id'           => $approver->id,
            'terminal_user_id'      => $terminalUser->id,
            'terminal_token_id'     => $this->tokenId($terminalUser),
            'ip_address'            => $request->ip(),
            'created_at'            => now(),
        ]);

        ActivityLogService::log('till_approval_signed_on_terminal', $record, [
            'approval_request_id' => $signed->id,
            'event'               => $signed->event,
            'version'             => $signed->version,
            'decision'            => $decision,
            'requester_id'        => $signed->maker_id,
            'approver_id'         => $approver->id,
            'terminal_user_id'    => $terminalUser->id,
            'status'              => $signed->status,
        ], ucfirst($decision) . " on the till: {$signed->event} asked by #{$signed->maker_id}, signed by #{$approver->id} with their PIN at #{$terminalUser->id}'s terminal", $approver);

        $message = match (true) {
            $decision === ApprovalSignature::REJECTED         => 'Rejected. The request went back to the person who raised it.',
            $signed->status === ApprovalRequest::APPROVED      => $signed->event === TillReversals::VOID ? 'Approved. The sale has been voided.' : 'Approved. The refund has been made.',
            default                                           => 'Signed. It now waits for the next approver.',
        };

        return response()->json([
            'message' => $message,
            'request' => $this->engine->present($signed->load(['signatures.signer', 'maker']), $terminalUser),
            'pending' => $this->reversals->pendingForOrder($order->id),
        ]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** The terminal session's token id (null for a transient / cookie session). */
    private function tokenId(User $user): ?int
    {
        $token = $user->currentAccessToken();

        return $token instanceof \Illuminate\Database\Eloquent\Model ? (int) $token->getKey() : null;
    }

    /** @return array{0: ApprovalRequest, 1: Order, 2: \Illuminate\Database\Eloquent\Model} */
    private function load(int $id): array
    {
        $approval = ApprovalRequest::whereIn('event', self::EVENTS)->findOrFail($id);
        $record   = $this->engine->handler($approval->event)->find((int) $approval->approvable_id);
        abort_if($record === null, 404, 'The sale this approval is for no longer exists.');

        $order = $record instanceof PosRefundRequest
            ? Order::withoutViewerScope()->findOrFail($record->order_id)
            : $record;

        return [$approval, $order, $record];
    }

    /**
     * May an approver sign at this outlet's till? The super admin and finance
     * (who hold no outlet) anywhere; anyone else only at an outlet they are
     * assigned to. An empty assignment means nowhere, never everywhere.
     */
    private function mayApproveAtOutlet(User $user, int $outletId): bool
    {
        if ($user->hasRole('super_admin', 'sanctum')) {
            return true;
        }
        try {
            if ($user->hasPermissionTo('approvals.finance_sign', 'sanctum')) {
                return true;
            }
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
        }

        return $user->outlets()->where('outlets.id', $outletId)->exists();
    }

    /**
     * The same outlet guard the till's own endpoints use (PosController::
     * authoriseOutletAccess): admins pass; anyone else needs the outlet among
     * their assignments, and an empty assignment means nothing.
     */
    private function authoriseOutlet(User $user, int $outletId): void
    {
        if ($user->isSuperAdmin() || $user->hasRole('super_admin') || $user->hasRole('admin')) {
            return;
        }
        $assigned = $user->outlets()->pluck('outlets.id');
        abort_if($assigned->isEmpty(), 403, 'Your account is not assigned to an outlet. Ask an administrator to assign one before using the till.');
        abort_unless($assigned->contains($outletId), 403, 'You do not have access to this outlet.');
    }
}
