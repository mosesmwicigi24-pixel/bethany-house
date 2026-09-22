<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ImprestAccount;
use App\Models\ImprestCashCount;
use App\Models\ImprestTopupRequest;
use App\Models\ImprestTransaction;
use App\Models\User;
use App\Services\ImprestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The imprest (petty-cash float) — see App\Services\ImprestService.
 *
 *   everyone who can record expenses: see the balance · ask for a top-up
 *   the custodian:   confirm what a top-up actually delivered
 *   anyone who records expenses, the custodian, the super admin: count the cash
 *                    (a difference moves nothing until the super admin approves it)
 *   approvers:       read the statement
 *   super admin:     set it up · change it · say a top-up was sent · decline ·
 *                    approve a cash-count adjustment · resolve rejected spends
 */
class ImprestController extends Controller
{
    public function __construct(private ImprestService $imprest) {}

    /** GET /expenses/imprest — the float(s) and what needs attention. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accounts = ImprestAccount::with(['custodian:id,first_name,last_name,email', 'outlet:id,name'])
            ->where('is_active', true)->orderBy('id')->get()
            ->map(fn (ImprestAccount $a) => $this->present($a, $user));

        return response()->json([
            'accounts'       => $accounts,
            'is_super_admin' => $this->isSuperAdmin($user),
            'can_set_up'     => $this->isSuperAdmin($user),
        ]);
    }

    /** GET /expenses/imprest/{id}/statement — the ledger with running balance. */
    public function statement(Request $request, int $id): JsonResponse
    {
        $account = ImprestAccount::findOrFail($id);
        $this->assertCanReadBook($request->user(), $account);

        return response()->json(
            ImprestTransaction::with(['creator:id,first_name,last_name', 'expense:id,reference_number,title,status,imprest_resolution'])
                ->where('imprest_account_id', $account->id)
                ->orderByDesc('id')
                ->paginate(min((int) $request->get('per_page', 30), 100))
        );
    }

    /** GET /expenses/imprest/{id}/topups */
    public function topups(Request $request, int $id): JsonResponse
    {
        $account = ImprestAccount::findOrFail($id);
        return response()->json(
            ImprestTopupRequest::with(['requester:id,first_name,last_name', 'sender:id,first_name,last_name',
                                       'receiver:id,first_name,last_name', 'decliner:id,first_name,last_name'])
                ->where('imprest_account_id', $account->id)
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'sent' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->paginate(20)
        );
    }

    /** GET /expenses/imprest/{id}/unreplenished — what the next top-up replaces. */
    public function unreplenished(Request $request, int $id): JsonResponse
    {
        $account = ImprestAccount::findOrFail($id);
        $rows = $this->imprest->unreplenished($account);
        return response()->json([
            'data'  => $rows,
            'total' => number_format((float) $rows->sum('amount_kes'), 2, '.', ''),
        ]);
    }

    /** GET /expenses/imprest/{id}/counts */
    public function counts(Request $request, int $id): JsonResponse
    {
        $account = ImprestAccount::findOrFail($id);
        $this->assertCanReadBook($request->user(), $account);
        return response()->json(
            ImprestCashCount::with(['counter:id,first_name,last_name', 'decider:id,first_name,last_name'])
                ->where('imprest_account_id', $account->id)->orderByDesc('id')->paginate(20)
        );
    }

    /** GET /expenses/imprest/unresolved — imprest spends rejected/cancelled after the cash left. */
    public function unresolved(Request $request): JsonResponse
    {
        return response()->json(
            Expense::withoutViewerScope()->with('createdBy:id,first_name,last_name')
                ->whereNotNull('imprest_account_id')->where('imprest_resolution', 'pending')
                ->orderBy('updated_at')->get(['id', 'reference_number', 'title', 'amount_kes', 'status', 'created_by', 'updated_at'])
        );
    }

    // ── super admin ───────────────────────────────────────────────────────────

    /** POST /expenses/imprest — set it up, with the cash counted in the box today. */
    public function store(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $data = $request->validate([
            'name'                => 'required|string|max:120',
            'custodian_id'        => 'required|integer|exists:users,id',
            'float_amount'        => 'required|numeric|min:1|max:10000000',
            'opening_balance'     => 'required|numeric|min:0|max:10000000',
            'low_balance_percent' => 'nullable|integer|min:1|max:90',
            'outlet_id'           => 'nullable|integer|exists:outlets,id',
        ]);
        $this->assertStaff((int) $data['custodian_id']);

        if (ImprestAccount::where('is_active', true)
            ->when($data['outlet_id'] ?? null, fn ($q, $o) => $q->where('outlet_id', $o), fn ($q) => $q->whereNull('outlet_id'))
            ->exists()) {
            return response()->json(['message' => 'An imprest already exists for this outlet.'], 422);
        }

        $account = $this->imprest->open($data, $request->user());
        return response()->json(['message' => 'Imprest set up.', 'account' => $this->present($account->fresh(['custodian', 'outlet']), $request->user())], 201);
    }

    /** PUT /expenses/imprest/{id} — custodian, float, alert level, name. Never the balance. */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $account = ImprestAccount::findOrFail($id);
        $data = $request->validate([
            'name'                => 'sometimes|string|max:120',
            'custodian_id'        => 'sometimes|integer|exists:users,id',
            'float_amount'        => 'sometimes|numeric|min:1|max:10000000',
            'low_balance_percent' => 'sometimes|integer|min:1|max:90',
        ]);
        if (isset($data['custodian_id'])) {
            $this->assertStaff((int) $data['custodian_id']);
            if (ImprestTopupRequest::where('imprest_account_id', $account->id)->where('status', ImprestTopupRequest::SENT)->exists()) {
                return response()->json(['message' => 'A top-up is on its way to the current custodian. Let them confirm it first.'], 422);
            }
        }
        $account->fill($data)->save();   // observed: before/after on the audit trail

        return response()->json(['message' => 'Saved.', 'account' => $this->present($account->fresh(['custodian', 'outlet']), $request->user())]);
    }

    /** POST /expenses/imprest/topups/{uuid}/send — the super admin says the money went. */
    public function send(Request $request, string $uuid): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $req = ImprestTopupRequest::where('uuid', $uuid)->firstOrFail();
        $data = $this->validateSend($request);
        $req = $this->imprest->markSent(ImprestAccount::findOrFail($req->imprest_account_id), $req, $data, $request->user());
        return response()->json(['message' => 'Marked as sent. The custodian confirms when it arrives.', 'request' => $req]);
    }

    /** POST /expenses/imprest/{id}/topups/direct — load without a request. */
    public function sendDirect(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $data = $this->validateSend($request);
        $req = $this->imprest->markSent(ImprestAccount::findOrFail($id), null, $data, $request->user());
        return response()->json(['message' => 'Marked as sent. The custodian confirms when it arrives.', 'request' => $req], 201);
    }

    /** POST /expenses/imprest/topups/{uuid}/decline {reason} */
    public function decline(Request $request, string $uuid): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $this->imprest->decline(ImprestTopupRequest::where('uuid', $uuid)->firstOrFail(), $request->user(), $data['reason']);
        return response()->json(['message' => 'Declined.']);
    }

    /** POST /expenses/imprest/counts/{id}/decide {approve, note} */
    public function decideCount(Request $request, int $countId): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        $this->imprest->decideCount(ImprestCashCount::findOrFail($countId), (bool) $data['approve'], $request->user(), $data['note'] ?? null);
        return response()->json(['message' => $data['approve'] ? 'Adjustment approved.' : 'Count rejected.']);
    }

    /** POST /expenses/{id}/imprest-resolution {resolution: returned|written_off, note} */
    public function resolve(Request $request, int $expenseId): JsonResponse
    {
        $this->assertSuperAdmin($request->user());
        $data = $request->validate(['resolution' => 'required|in:returned,written_off', 'note' => 'nullable|string|max:1000']);
        $expense = Expense::withoutViewerScope()->findOrFail($expenseId);
        $this->imprest->resolve($expense, $data['resolution'], $request->user(), $data['note'] ?? null);
        return response()->json(['message' => $data['resolution'] === 'returned' ? 'Cash returned to the imprest.' : 'Written off.']);
    }

    // ── staff ─────────────────────────────────────────────────────────────────

    /** POST /expenses/imprest/{id}/topups {amount?, reason?} — anyone who records expenses. */
    public function requestTopup(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:1|max:10000000',
            'reason' => 'nullable|string|max:1000',
        ]);
        $req = $this->imprest->requestTopup(ImprestAccount::findOrFail($id), $request->user(),
            isset($data['amount']) ? (string) $data['amount'] : null, $data['reason'] ?? null);
        return response()->json(['message' => 'Top-up requested.', 'request' => $req], 201);
    }

    /** POST /expenses/imprest/topups/{uuid}/cancel — your own, before it is sent. */
    public function cancelTopup(Request $request, string $uuid): JsonResponse
    {
        $this->imprest->cancel(ImprestTopupRequest::where('uuid', $uuid)->firstOrFail(), $request->user());
        return response()->json(['message' => 'Cancelled.']);
    }

    /** POST /expenses/imprest/topups/{uuid}/receive {amount, note} — the custodian. */
    public function receive(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:10000000', 'note' => 'nullable|string|max:1000']);
        $req = $this->imprest->confirmReceived(ImprestTopupRequest::where('uuid', $uuid)->firstOrFail(),
            (string) $data['amount'], $request->user(), $data['note'] ?? null);
        return response()->json(['message' => 'Received. The imprest balance is updated.', 'request' => $req]);
    }

    /**
     * POST /expenses/imprest/{id}/counts {counted_amount, note}
     * Anyone who records expenses may count what is in the box (approved plan,
     * 2026-09-22); a count that differs from the books only waits for the
     * super admin — it never moves the balance by itself.
     */
    public function recordCount(Request $request, int $id): JsonResponse
    {
        $account = ImprestAccount::findOrFail($id);
        $user = $request->user();
        abort_unless((int) $account->custodian_id === (int) $user->id || $this->isSuperAdmin($user) || $user->can('expenses.create'), 403,
            'Only staff who record expenses, the custodian or the super admin can count the imprest.');
        $data = $request->validate(['counted_amount' => 'required|numeric|min:0|max:10000000', 'note' => 'nullable|string|max:1000']);
        $count = $this->imprest->recordCount($account, (string) $data['counted_amount'], $request->user(), $data['note'] ?? null);
        return response()->json([
            'message' => (float) $count->variance === 0.0 ? 'The count matches the books.' : 'Recorded. The super admin approves the difference.',
            'count'   => $count,
        ], 201);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function present(ImprestAccount $a, User $viewer): array
    {
        $pending = ImprestTopupRequest::with(['requester:id,first_name,last_name', 'sender:id,first_name,last_name'])
            ->where('imprest_account_id', $a->id)
            ->whereIn('status', [ImprestTopupRequest::PENDING, ImprestTopupRequest::SENT])->latest('id')->first();

        return [
            'id'                  => $a->id,
            'name'                => $a->name,
            'outlet'              => $a->outlet?->only(['id', 'name']),
            'custodian'           => $a->custodian?->only(['id', 'first_name', 'last_name', 'email']),
            'currency_code'       => $a->currency_code,
            'float_amount'        => $a->float_amount,
            'balance'             => $a->balance,
            'low_balance_percent' => $a->low_balance_percent,
            'low_threshold'       => number_format($a->lowBalanceThreshold(), 2, '.', ''),
            'is_low'              => $a->isLow(),
            'suggested_topup'     => ImprestService::money(max(0, ImprestService::cents($a->float_amount) - ImprestService::cents($a->balance))),
            'open_topup'          => $pending,
            'pending_counts'      => ImprestCashCount::where('imprest_account_id', $a->id)->where('status', ImprestCashCount::PENDING)->count(),
            'unresolved_expenses' => Expense::withoutViewerScope()->where('imprest_account_id', $a->id)->where('imprest_resolution', 'pending')->count(),
            'you_are_custodian'   => (int) $a->custodian_id === (int) $viewer->id,
            'can_read_book'       => $this->canReadBook($viewer, $a),
        ];
    }

    private function validateSend(Request $request): array
    {
        return $request->validate([
            'amount'    => 'required|numeric|min:1|max:10000000',
            'method'    => 'required|in:mpesa,cash,bank_transfer,other',
            'reference' => 'nullable|string|max:100',
            'sent_at'   => 'nullable|date|before_or_equal:now',
            'note'      => 'nullable|string|max:1000',
        ]);
    }

    private function isSuperAdmin(?User $u): bool
    {
        return $u !== null && $u->hasRole('super_admin', 'sanctum');
    }

    private function assertSuperAdmin(?User $u): void
    {
        abort_unless($this->isSuperAdmin($u), 403, 'Only the super admin can do this.');
    }

    private function canReadBook(User $u, ImprestAccount $a): bool
    {
        return $this->isSuperAdmin($u) || (int) $a->custodian_id === (int) $u->id || $u->can('expenses.approve');
    }

    private function assertCanReadBook(User $u, ImprestAccount $a): void
    {
        abort_unless($this->canReadBook($u, $a), 403, 'The imprest statement is for the custodian, approvers and the super admin.');
    }

    private function assertStaff(int $userId): void
    {
        $u = User::find($userId);
        abort_unless($u && $u->canAccessAdmin() && $u->status === 'active', 422, 'The custodian must be an active staff member.');
    }
}
