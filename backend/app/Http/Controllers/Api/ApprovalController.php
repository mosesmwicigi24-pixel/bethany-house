<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Services\Approvals\ApprovalEngine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Approvals inbox (Phase 3B).
 *
 *   GET  /admin/approvals/inbox            what the caller can sign now — their band, never their own
 *   GET  /admin/approvals/mine             what the caller submitted, with where each one stands
 *   GET  /admin/approvals/{id}             one request (its maker, a current signer, or a past signer)
 *   POST /admin/approvals/{id}/sign        {approvable_id, version, notes?}
 *   POST /admin/approvals/{id}/reject      {approvable_id, version, reason}
 *   POST /admin/approvals/{id}/resubmit    the maker, after a rejection or expiry → a new version
 *   GET  /admin/approvals/thresholds       the bands in force (signers and the owner)
 *   PUT  /admin/approvals/thresholds/{event}  super admin only; audited
 *
 * Who may sign is the engine's decision (band key, maker ≠ checker, one
 * signature per person); these routes only need an authenticated staff user.
 */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalEngine $engine) {}

    public function inbox(Request $request): JsonResponse
    {
        $user  = $request->user();
        $items = $this->engine->inbox($user);

        return response()->json([
            'data'  => $items->map(fn ($r) => $this->engine->present($r, $user))->values(),
            'count' => $items->count(),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->engine->mine($user)->map(fn ($r) => $this->engine->present($r, $user))->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $r    = ApprovalRequest::with(['signatures.signer', 'maker'])->findOrFail($id);

        $involved = (int) $r->maker_id === (int) $user->id
            || $r->signatures->contains(fn ($s) => (int) $s->signer_id === (int) $user->id)
            || $this->engine->canSignNow($user, $r)
            || $user->hasRole('super_admin', 'sanctum');
        abort_unless($involved, 403, 'This approval is not yours to see.');

        return response()->json(['request' => $this->engine->present($r, $user)]);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approvable_id' => 'required|integer',
            'version'       => 'required|integer|min:1',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $r = $this->engine->sign(
            ApprovalRequest::findOrFail($id), $request->user(), ApprovalSignature::APPROVED,
            $data['notes'] ?? null, (int) $data['approvable_id'], (int) $data['version'],
        );

        return response()->json([
            'message' => $r->status === ApprovalRequest::APPROVED ? 'Approved.' : 'Signed. It now waits for the next band.',
            'request' => $this->engine->present($r->load(['signatures.signer', 'maker']), $request->user()),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approvable_id' => 'required|integer',
            'version'       => 'required|integer|min:1',
            'reason'        => 'required|string|min:3|max:1000',
        ]);

        $r = $this->engine->sign(
            ApprovalRequest::findOrFail($id), $request->user(), ApprovalSignature::REJECTED,
            $data['reason'], (int) $data['approvable_id'], (int) $data['version'],
        );

        return response()->json([
            'message' => 'Rejected. It went back to the person who raised it.',
            'request' => $this->engine->present($r->load(['signatures.signer', 'maker']), $request->user()),
        ]);
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $new = $this->engine->resubmit(ApprovalRequest::findOrFail($id), $request->user());

        return response()->json([
            'message' => 'Resubmitted as a new version.',
            'request' => $this->engine->present($new->load(['signatures.signer', 'maker']), $request->user()),
        ], 201);
    }

    public function thresholds(Request $request): JsonResponse
    {
        // The bands are for the people who sign them (and the owner).
        $u = $request->user();
        abort_unless(
            $u->can('procurement.approve') || $u->can('inventory.approve') || $u->can('expenses.approve')
            || $u->can('approvals.finance_sign') || $u->can('payments.void') || $u->can('payments.reassign')
            || $u->can('approvals.super_sign'),
            403, 'The approval thresholds are for those who sign them.',
        );

        return response()->json(['data' => $this->engine->thresholds()->overview()]);
    }

    public function updateThresholds(Request $request, string $event): JsonResponse
    {
        // The owner's alone (plan §5: "changing one is a super_admin-only,
        // audited event"). Checked on the role, not a permission: no grant can
        // hand this to anyone else.
        abort_unless($request->user()->hasRole('super_admin', 'sanctum'), 403, 'Only the super admin changes approval thresholds.');

        $data = $request->validate([
            'effective_from'              => 'nullable|date',
            'bands'                       => 'required|array|min:1|max:6',
            'bands.*.up_to_kes'           => 'nullable|numeric|min:0',
            'bands.*.approver_permission' => 'required|string|max:125',
        ]);

        $rows = $this->engine->thresholds()->replace(
            $event,
            $data['bands'],
            $request->user(),
            isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : null,
        );

        return response()->json([
            'message'        => 'Thresholds saved.',
            'event'          => $event,
            'effective_from' => $rows->first()->effective_from->toIso8601String(),
            'bands'          => $rows->map(fn ($b) => $this->engine->thresholds()->present($b))->values(),
        ]);
    }
}
