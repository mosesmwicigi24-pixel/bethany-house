<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceRepayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\People\Payroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Advances and loans (People PR 7). A person asks on the phone; payroll.run
 * approves (step-up), records the payout, and payroll takes the instalments.
 * A cash repayment outside payroll is recorded here too. Balance is always
 * amount − Σ repayments.
 */
class AdvancesController extends Controller
{
    // ── Me ──────────────────────────────────────────────────────────────────

    /** GET /me/advances */
    public function mine(Request $request): JsonResponse
    {
        $rows = StaffAdvance::with('repayments')->where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(50)->get();
        $open = StaffAdvance::where('user_id', $request->user()->id)->where('status', 'paid')->sum('balance');

        return response()->json(['data' => $rows->map(fn ($a) => $this->advance($a))->values(), 'open_balance' => (float) $open]);
    }

    /** POST /me/advances {kind, amount, instalment?, reason} */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kind'       => 'required|in:advance,loan',
            'amount'     => 'required|numeric|min:1|max:9999999',
            'instalment' => 'nullable|numeric|min:1|max:9999999',
            'reason'     => 'required|string|max:300',
        ]);
        $u = $request->user();
        if (StaffAdvance::where('user_id', $u->id)->where('status', 'requested')->exists()) {
            return response()->json(['message' => 'You already have a request waiting. Cancel it first to ask for a different amount.'], 422);
        }
        $instalment = $v['kind'] === 'advance' ? $v['amount'] : min((float) ($v['instalment'] ?? $v['amount']), (float) $v['amount']);
        $a = StaffAdvance::create(['user_id' => $u->id, 'kind' => $v['kind'], 'amount' => $v['amount'], 'instalment' => $instalment, 'balance' => $v['amount'], 'reason' => $v['reason'],
            'status' => 'requested', 'requested_at' => now(), 'created_by' => $u->id]);
        ActivityLogService::log('advance_requested', $a, ['kind' => $a->kind], "{$u->first_name} asked for a {$a->kind}");
        $deciders = User::permission('payroll.run')->where('status', 'active')->get();
        if ($deciders->isNotEmpty()) {
            NotificationService::deliver($deciders, new InAppNotification(title: "{$u->first_name} asked for " . ($a->kind === 'loan' ? 'a loan' : 'an advance'), body: $a->reason, actionUrl: '/payroll?tab=advances', icon: 'people'));
        }

        return response()->json(['message' => 'Sent. Payroll will decide and you will be told.', 'advance' => $this->advance($a)], 201);
    }

    /** DELETE /me/advances/{id} — only while requested. */
    public function cancelMine(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($a->status !== 'requested', 422, 'Only a request still waiting can be cancelled.');
        $a->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled.']);
    }

    // ── Payroll side ────────────────────────────────────────────────────────

    /** GET /payroll/advances?status= */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => 'nullable|string'])['status'] ?? null;
        $q = StaffAdvance::with(['user:id,first_name,last_name', 'repayments', 'decidedBy:id,first_name,last_name'])->orderByRaw("case status when 'requested' then 0 when 'paid' then 1 when 'approved' then 2 else 3 end")->orderByDesc('created_at');
        if ($status) $q->where('status', $status);
        $rows = $q->limit(200)->get();
        $open = StaffAdvance::where('status', 'paid')->sum('balance');

        return response()->json(['data' => $rows->map(fn ($a) => $this->advance($a, true))->values(), 'open_balance' => (float) $open, 'can_run' => $request->user()->can('payroll.run')]);
    }

    /** POST /payroll/advances — payroll.run + step.up: for a person, approved at once. */
    public function storeForPerson(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'kind'       => 'required|in:advance,loan',
            'amount'     => 'required|numeric|min:1|max:9999999',
            'instalment' => 'nullable|numeric|min:1|max:9999999',
            'reason'     => 'required|string|max:300',
        ]);
        $person = User::where('user_type', 'staff')->findOrFail($v['user_id']);
        $instalment = $v['kind'] === 'advance' ? $v['amount'] : min((float) ($v['instalment'] ?? $v['amount']), (float) $v['amount']);
        $a = StaffAdvance::create(['user_id' => $person->id, 'kind' => $v['kind'], 'amount' => $v['amount'], 'instalment' => $instalment, 'balance' => $v['amount'], 'reason' => $v['reason'],
            'status' => 'approved', 'requested_at' => now(), 'decided_by' => $request->user()->id, 'decided_at' => now(), 'created_by' => $request->user()->id]);
        ActivityLogService::log('advance_approved', $a, ['kind' => $a->kind], "{$request->user()->first_name} recorded and approved a {$a->kind} for {$person->first_name}");

        return response()->json(['message' => 'Recorded as approved. Record the payout when the money goes.', 'advance' => $this->advance($a->load('user'), true)], 201);
    }

    /** POST /payroll/advances/{id}/approve {instalment?, note?} — payroll.run + step.up */
    public function approve(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::with('user')->findOrFail($id);
        abort_if($a->status !== 'requested', 422, 'Only a waiting request is approved.');
        abort_if($a->user_id === $request->user()->id, 422, 'Nobody approves their own advance.');
        $v = $request->validate(['instalment' => 'nullable|numeric|min:1', 'note' => 'nullable|string|max:300']);
        $a->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $v['note'] ?? null,
            'instalment' => $a->kind === 'advance' ? $a->amount : min((float) ($v['instalment'] ?? $a->instalment), (float) $a->amount)]);
        ActivityLogService::log('advance_approved', $a, [], "{$request->user()->first_name} approved {$a->user->first_name}'s {$a->kind}");
        NotificationService::deliver($a->user, new InAppNotification(title: ucfirst($a->kind) . ' approved', body: 'You will be told when it is paid out.', actionUrl: '/me/advances', icon: 'people'));

        return response()->json(['message' => 'Approved.', 'advance' => $this->advance($a, true)]);
    }

    /** POST /payroll/advances/{id}/decline {note} — payroll.run + step.up */
    public function decline(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::with('user')->findOrFail($id);
        abort_if($a->status !== 'requested', 422, 'Only a waiting request is declined.');
        $v = $request->validate(['note' => 'required|string|max:300']);
        $a->update(['status' => 'declined', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $v['note']]);
        NotificationService::deliver($a->user, new InAppNotification(title: ucfirst($a->kind) . ' declined', body: $v['note'], actionUrl: '/me/advances', icon: 'people'));

        return response()->json(['message' => 'Declined.', 'advance' => $this->advance($a, true)]);
    }

    /** POST /payroll/advances/{id}/payout {payment_method, payment_reference?} — payroll.run + step.up. From here payroll takes instalments. */
    public function payout(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::with('user')->findOrFail($id);
        abort_if($a->status !== 'approved', 422, 'Approve it first; a payout is recorded once.');
        $v = $request->validate(['payment_method' => 'required|in:mpesa,bank,cash', 'payment_reference' => 'nullable|string|max:80']);
        $a->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now(), 'payment_method' => $v['payment_method'], 'payment_reference' => $v['payment_reference'] ?? null]);
        ActivityLogService::log('advance_paid_out', $a, ['method' => $v['payment_method']], "{$request->user()->first_name} paid out {$a->user->first_name}'s {$a->kind}");
        NotificationService::deliver($a->user, new InAppNotification(title: ucfirst($a->kind) . ' paid out', body: 'Repayment comes off your next pay' . ($a->kind === 'loan' ? ' in instalments.' : '.'), actionUrl: '/me/advances', icon: 'people'));

        return response()->json(['message' => 'Payout recorded.', 'advance' => $this->advance($a, true)]);
    }

    /** POST /payroll/advances/{id}/repay {amount, reference?} — a cash repayment outside payroll. payroll.run + step.up */
    public function repay(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::findOrFail($id);
        abort_if($a->status !== 'paid', 422, 'Only a paid-out advance takes a repayment.');
        $v = $request->validate(['amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:80']);
        abort_if((float) $v['amount'] > (float) $a->balance + 0.005, 422, 'More than the balance.');
        DB::transaction(function () use ($a, $v, $request) {
            AdvanceRepayment::create(['staff_advance_id' => $a->id, 'kind' => 'cash', 'amount' => $v['amount'], 'recorded_on' => now()->toDateString(), 'reference' => $v['reference'] ?? null, 'recorded_by' => $request->user()->id]);
            Payroll::refreshAdvance($a);
        });

        return response()->json(['message' => 'Repayment recorded.', 'advance' => $this->advance($a->fresh(['user', 'repayments']), true)]);
    }

    private function advance(StaffAdvance $a, bool $full = false): array
    {
        $out = [
            'id' => $a->id, 'kind' => $a->kind, 'status' => $a->status, 'amount' => (float) $a->amount, 'instalment' => (float) $a->instalment, 'balance' => (float) $a->balance,
            'reason' => $a->reason, 'requested_at' => $a->requested_at?->toIso8601String(), 'decided_at' => $a->decided_at?->toIso8601String(), 'decision_note' => $a->decision_note,
            'paid_at' => $a->paid_at?->toIso8601String(), 'payment_method' => $a->payment_method, 'payment_reference' => $a->payment_reference, 'settled_at' => $a->settled_at?->toIso8601String(),
            'repayments' => $a->relationLoaded('repayments') ? $a->repayments->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'amount' => (float) $r->amount, 'on' => $r->recorded_on->toDateString(), 'reference' => $r->reference])->values() : [],
        ];
        if ($full) {
            $out['user'] = $a->relationLoaded('user') && $a->user ? ['id' => $a->user->id, 'name' => trim("{$a->user->first_name} {$a->user->last_name}")] : ['id' => $a->user_id];
            $out['decided_by'] = $a->relationLoaded('decidedBy') && $a->decidedBy ? trim("{$a->decidedBy->first_name} {$a->decidedBy->last_name}") : null;
        }
        return $out;
    }
}
