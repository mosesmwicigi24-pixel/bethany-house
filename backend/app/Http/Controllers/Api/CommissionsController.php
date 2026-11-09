<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffCommission;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission on top of salary (owner, 2026-11-03/04).
 *
 *   finance records   POST /payroll/commissions            (payroll.run) — waits for a super admin
 *   super admin       records and approves in one step (owner, 2026-11-04: a
 *                     super admin does not wait for another account), or
 *                     approves finance's: POST /payroll/commissions/{id}/approve (step-up)
 *                     A commission paid to the super admin themselves still
 *                     needs another super admin.
 *   either voids      POST /payroll/commissions/{id}/void   (with a reason, until it is on a payslip)
 *   the pay run pays  approved commissions ride the next run; paying the line pays them
 *
 * A person's balance is what is approved and not yet paid. Whether PAYE is
 * charged on commission is one switch, a super admin's (off for now).
 */
class CommissionsController extends Controller
{
    /** GET /payroll/commissions?user_id=&status= (payroll.view) */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id' => 'nullable|integer',
            'status'  => 'nullable|in:recorded,approved,paid,void,open',
        ]);
        $rows = StaffCommission::with(['user:id,first_name,last_name', 'creator:id,first_name,last_name', 'approver:id,first_name,last_name', 'line.run:id,reference', 'order:id,order_number'])
            ->when($v['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($v['status'] ?? null, fn ($q, $s) => $s === 'open' ? $q->whereIn('status', ['recorded', 'approved']) : $q->where('status', $s))
            ->orderByDesc('earned_on')->orderByDesc('id')->limit(500)->get();

        $sum = fn (string $status) => round((float) StaffCommission::where('status', $status)
            ->when($v['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))->sum('amount'), 2);

        $me = $request->user();

        return response()->json([
            'data'   => $rows->map(fn (StaffCommission $c) => $this->row($c, $me))->values(),
            'totals' => ['awaiting_approval' => $sum('recorded'), 'owed' => $sum('approved'), 'paid' => $sum('paid')],
            'paye_applies' => StaffCommission::payeApplies(),
            'can_record'   => $me->can('payroll.run'),
            'can_approve'  => $me->isSuperAdmin(),
        ]);
    }

    /** POST /payroll/commissions {user_id, transaction_ref, amount, earned_on, note?} (payroll.run) */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id'         => 'required|integer',
            'transaction_ref' => 'required|string|min:2|max:80',
            'amount'          => 'required|numeric|min:1|max:10000000',
            'earned_on'       => 'required|date|before_or_equal:today',
            'note'            => 'nullable|string|max:500',
        ]);
        $person = User::where('user_type', 'staff')->findOrFail($v['user_id']);
        $ref = trim($v['transaction_ref']);

        $dupe = StaffCommission::where('user_id', $person->id)->where('status', '<>', 'void')
            ->whereRaw('lower(transaction_ref) = ?', [mb_strtolower($ref)])->exists();
        if ($dupe) {
            return response()->json(['message' => "{$person->first_name} already has a commission for {$ref}."], 422);
        }

        // A hub order number links the commission to its order.
        $orderId = DB::table('orders')->whereRaw('upper(order_number) = ?', [mb_strtoupper($ref)])->value('id');

        // A super admin's own entry is approved as it is recorded — behind the
        // same step-up an approval asks for — unless it is their own commission.
        $me = $request->user();
        $selfApproved = $me->isSuperAdmin() && $person->id !== $me->id;
        if ($selfApproved) {
            app(\App\Services\Auth\StepUp::class)->ensure($request);
        }

        $c = StaffCommission::create([
            'user_id' => $person->id, 'transaction_ref' => $ref, 'order_id' => $orderId,
            'amount' => round((float) $v['amount'], 2), 'earned_on' => $v['earned_on'], 'note' => $v['note'] ?? null,
            'status' => $selfApproved ? 'approved' : 'recorded', 'created_by' => $me->id,
            'approved_by' => $selfApproved ? $me->id : null, 'approved_at' => $selfApproved ? now() : null,
        ]);
        ActivityLogService::log($selfApproved ? 'commission_approved' : 'commission_recorded', $person,
            ['commission_id' => $c->id, 'transaction_ref' => $ref, 'amount' => (string) $c->amount, 'recorded_and_approved' => $selfApproved],
            "{$me->first_name} " . ($selfApproved ? 'recorded and approved' : 'recorded') . " a commission of KES {$c->amount} for {$person->first_name} on {$ref}");

        return response()->json(['message' => $selfApproved
            ? 'Commission recorded and approved. It is added to the next pay run.'
            : 'Commission recorded. A super admin approves it before it is paid.',
            'commission' => $this->row($c->fresh(['user', 'creator', 'approver', 'order']), $me)], 201);
    }

    /** POST /payroll/commissions/{id}/approve (super admin + step-up; not one they recorded) */
    public function approve(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        abort_if(!$me->isSuperAdmin(), 403, 'Only a super admin approves commission.');
        $c = StaffCommission::findOrFail($id);
        abort_if($c->status !== 'recorded', 422, 'Only a recorded commission can be approved.');
        abort_if($c->user_id === $me->id, 403, 'Your own commission is approved by another super admin.');

        $c->update(['status' => 'approved', 'approved_by' => $me->id, 'approved_at' => now()]);
        ActivityLogService::log('commission_approved', $c->user, ['commission_id' => $c->id, 'amount' => (string) $c->amount],
            "{$me->first_name} approved KES {$c->amount} commission ({$c->transaction_ref}) for {$c->user->first_name}");

        return response()->json(['message' => 'Approved. It is added to the next pay run.', 'commission' => $this->row($c->fresh(['user', 'creator', 'approver', 'order']), $me)]);
    }

    /** POST /payroll/commissions/{id}/void {reason} (payroll.run) — until it is on an approved payslip. */
    public function void(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => 'required|string|min:3|max:500'])['reason'];
        $c = StaffCommission::findOrFail($id);
        abort_if(!in_array($c->status, ['recorded', 'approved'], true) || $c->payroll_line_id !== null, 422,
            'A commission already on an approved payslip cannot be voided.');

        $c->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => $request->user()->id, 'voided_at' => now()]);
        ActivityLogService::log('commission_voided', $c->user, ['commission_id' => $c->id, 'reason' => $reason],
            "{$request->user()->first_name} voided the {$c->transaction_ref} commission for {$c->user->first_name}: {$reason}");

        return response()->json(['message' => 'Commission voided.']);
    }

    /** POST /payroll/commissions/paye {applies} (super admin + step-up) — PAYE on commission, on or off. */
    public function setPaye(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if(!$me->isSuperAdmin(), 403, 'Only a super admin changes whether PAYE applies to commission.');
        $applies = (bool) $request->validate(['applies' => 'required|boolean'])['applies'];

        DB::table('settings')->updateOrInsert(['key' => 'payroll.commission_paye'],
            ['value' => $applies ? '1' : '0', 'data_type' => 'boolean', 'group' => 'payroll', 'is_public' => false, 'updated_at' => now()]);
        ActivityLogService::log($applies ? 'commission_paye_on' : 'commission_paye_off', null, ['applies' => $applies],
            "{$me->first_name} turned PAYE on commission " . ($applies ? 'on' : 'off'));

        return response()->json(['message' => $applies
            ? 'PAYE now applies to commission on runs drafted from now on.'
            : 'PAYE no longer applies to commission on runs drafted from now on.', 'paye_applies' => $applies]);
    }

    private function row(StaffCommission $c, User $me): array
    {
        $name = fn (?User $u) => $u ? trim("{$u->first_name} {$u->last_name}") : null;

        return [
            'id'              => $c->id,
            'user'            => ['id' => $c->user_id, 'name' => $name($c->user)],
            'transaction_ref' => $c->transaction_ref,
            'order'           => $c->order ? ['id' => $c->order->id, 'number' => $c->order->order_number] : null,
            'amount'          => (float) $c->amount,
            'earned_on'       => $c->earned_on->toDateString(),
            'note'            => $c->note,
            'status'          => $c->status,
            'recorded_by'     => $name($c->creator),
            'approved_by'     => $name($c->approver),
            'approved_at'     => $c->approved_at?->toIso8601String(),
            'run'             => $c->line?->run?->reference,
            'paid_on'         => $c->paid_at?->toDateString(),
            'void_reason'     => $c->void_reason,
            'can_approve'     => $c->status === 'recorded' && $me->isSuperAdmin() && $c->user_id !== $me->id,
            'can_void'        => in_array($c->status, ['recorded', 'approved'], true) && $c->payroll_line_id === null && $me->can('payroll.run'),
        ];
    }
}
