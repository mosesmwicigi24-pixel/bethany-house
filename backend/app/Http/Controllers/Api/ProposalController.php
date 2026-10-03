<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChangeProposal;
use App\Services\Approvals\ProposalService;
use App\Services\Approvals\ProposalThresholds;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proposals (Phase 3C) — changes to money-relevant values that take effect
 * only after the required signatures. Signing happens in the Approvals inbox
 * (ApprovalController); these routes propose, preview, list and withdraw.
 *
 *   GET  /admin/proposals?event=&subject_type=&subject_id=&status=open|all&mine=1
 *   POST /admin/proposals            {event, subject_id, changes:{field:value}, effective_from?, note?}
 *   POST /admin/proposals/preview    same body → straight through, or who must sign
 *   GET  /admin/proposals/{id}
 *   POST /admin/proposals/{id}/withdraw
 *   GET  /admin/proposals/thresholds
 *   PUT  /admin/proposals/thresholds/{event}   super admin only; audited
 *
 * Who may propose each event is the event's maker keys (ProposalHandler::
 * makerPermissions), checked by ProposalService; who may read them is a maker,
 * a finance signer or the owner.
 */
class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposals, private ProposalThresholds $thresholds) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event'        => 'nullable|string|max:64',
            'subject_type' => 'nullable|string|max:40',
            'subject_id'   => 'nullable|integer',
            'status'       => 'nullable|in:open,all',
            'mine'         => 'nullable|boolean',
            'limit'        => 'nullable|integer|min:1|max:200',
        ]);
        $user = $request->user();

        $visible = array_keys(array_filter($this->proposals->handlers(), fn ($h) => $this->proposals->maySee($user, $h)));
        if (!empty($data['event'])) {
            $visible = array_values(array_intersect($visible, [$data['event']]));
        }

        $items = ChangeProposal::with(['maker:id,first_name,last_name,email'])
            ->whereIn('event', $visible)
            ->when(!empty($data['subject_type']), fn ($q) => $q->where('subject_type', $data['subject_type']))
            ->when(isset($data['subject_id']), fn ($q) => $q->where('subject_id', (int) $data['subject_id']))
            ->when(($data['status'] ?? 'all') === 'open', fn ($q) => $q->whereIn('status', ChangeProposal::OPEN))
            ->when(!empty($data['mine']), fn ($q) => $q->where('maker_id', $user->id))
            ->orderByDesc('id')
            ->limit($data['limit'] ?? 50)
            ->get();

        return response()->json([
            'data'  => $items->map(fn ($p) => $this->proposals->present($p, $user))->values(),
            'count' => $items->count(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $p = ChangeProposal::with('maker')->findOrFail($id);
        abort_unless($this->proposals->maySee($request->user(), $this->proposals->handler($p->event)), 403, 'This change is not yours to see.');

        return response()->json(['proposal' => $this->proposals->present($p, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $p = $this->proposals->propose(
            $data['event'], (int) $data['subject_id'], $data['changes'], $request->user(),
            $data['effective_from'] ?? null, $data['note'] ?? null,
        );

        if ($p === null) {
            return response()->json(['message' => 'Nothing to change.', 'proposal' => null]);
        }

        return response()->json([
            'message'  => $this->proposals->message($p),
            'proposal' => $this->proposals->present($p->load('maker'), $request->user()),
        ], $p->status === ChangeProposal::APPLIED ? 200 : 202);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($this->proposals->preview(
            $data['event'], (int) $data['subject_id'], $data['changes'], $request->user(), $data['effective_from'] ?? null,
        ));
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $p = $this->proposals->withdraw(ChangeProposal::findOrFail($id), $request->user());

        return response()->json([
            'message'  => 'Withdrawn. The current value is unchanged.',
            'proposal' => $this->proposals->present($p->load('maker'), $request->user()),
        ]);
    }

    public function thresholds(): JsonResponse
    {
        return response()->json(['data' => $this->thresholds->overview()]);
    }

    public function updateThresholds(Request $request, string $event): JsonResponse
    {
        // The owner's alone, as for the 3B thresholds: checked on the role.
        abort_unless($request->user()->hasRole('super_admin', 'sanctum'), 403, 'Only the super admin changes approval thresholds.');

        $data = $request->validate([
            'effective_from'              => 'nullable|date',
            'bands'                       => 'required|array|min:1|max:6',
            'bands.*.up_to_kes'           => 'nullable|numeric|min:0',
            'bands.*.approver_permission' => 'required|string|max:125',
        ]);

        $rows = $this->thresholds->replace($event, $data['bands'], $request->user(),
            isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : null);

        return response()->json([
            'message'        => 'Thresholds saved.',
            'event'          => $event,
            'effective_from' => $rows->first()->effective_from->toIso8601String(),
            'bands'          => $rows->map(fn ($b) => app(\App\Services\Approvals\ThresholdRepository::class)->present($b))->values(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'event'          => 'required|string|max:64',
            'subject_id'     => 'required|integer|min:1',
            'changes'        => 'required|array|min:1',
            'effective_from' => 'nullable|date',
            'note'           => 'nullable|string|max:1000',
        ]);
    }
}
