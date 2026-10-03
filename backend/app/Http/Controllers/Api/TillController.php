<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\TillDiscrepancy;
use App\Services\Tills\TillPresenter;
use App\Services\Tills\TillService;
use App\Services\Tills\TillVisibility;
use App\Support\MakerChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The till lifecycle's back office (role hardening Phase 4B, part 1).
 *
 * Opening and the operator's blind count stay on PosController
 * (register/open, register/close) because that is where the till is worked.
 * Everything after the count lives here:
 *
 *   GET   /admin/pos/tills                    list, scoped by TillVisibility
 *   GET   /admin/pos/tills/{id}               one till; blind to its operator until finalized
 *   PATCH /admin/pos/tills/{id}               closing notes, before finalizing; money never (409 once closed/finalized)
 *   POST  /admin/pos/tills/{id}/finalize      pos.till_verify, at the verifier's outlet, verifier ≠ operator
 *   POST  /admin/pos/tills/{id}/reconcile     pos.reconcile, finalized tills, reconciler ≠ operator/verifier
 *   POST  /admin/pos/tills/{id}/corrections   pos.till_correction, finalized tills — a linked record, never a reopen
 *
 * A till the caller may not see answers 404, not 403: whether a colleague's
 * till exists is itself not hers to know.
 */
class TillController extends Controller
{
    /** Fields that carry money or lifecycle state. Never accepted by PATCH. */
    private const MONEY_FIELDS = [
        'status', 'opening_balance', 'opening_cash', 'closing_balance', 'closing_cash', 'expected_cash',
        'actual_cash', 'counted_cash', 'total_sales', 'total_cash_sales', 'total_card_sales',
        'total_mpesa_sales', 'total_refunds', 'transaction_count', 'denomination_count',
        'expected_cash_at_count', 'expected_cash_running_at_count', 'variance', 'variance_class',
        'variance_reason', 'finalized_at', 'verified_by', 'opened_at', 'closed_at', 'outlet_id',
        'currency_code', 'opened_by', 'closed_by',
    ];

    public function __construct(private TillService $tills, private TillPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'      => 'nullable|integer',
            'stage'          => 'nullable|in:open,awaiting_verification,finalized,closed_unverified',
            'reconciliation' => 'nullable|in:none,matched,mismatch',
            'discrepancy'    => 'nullable|in:any,logged,awaiting_approval',
            'per_page'       => 'nullable|integer|min:5|max:100',
        ]);

        $user  = $request->user();
        $query = TillVisibility::scope(CashRegister::query(), $user)
            ->with(['outlet:id,name', 'openedBy:id,first_name,last_name', 'closedBy:id,first_name,last_name']);

        if (!empty($validated['outlet_id'])) {
            $query->where('outlet_id', (int) $validated['outlet_id']);
        }

        match ($validated['stage'] ?? null) {
            'open'                  => $query->where('status', 'open'),
            'awaiting_verification' => $query->where('status', 'counted'),
            'finalized'             => $query->whereNotNull('finalized_at'),
            'closed_unverified'     => $query->where('status', 'closed')->whereNull('finalized_at'),
            default                 => null,
        };

        if (isset($validated['reconciliation'])) {
            $latest = fn ($q) => $q->from('till_reconciliations as tr')
                ->whereColumn('tr.cash_register_id', 'cash_registers.id')
                ->whereRaw('tr.id = (SELECT MAX(id) FROM till_reconciliations WHERE cash_register_id = cash_registers.id)');
            match ($validated['reconciliation']) {
                'none'     => $query->whereNotNull('finalized_at')->whereNotExists(fn ($q) => $q->from('till_reconciliations as tr')->whereColumn('tr.cash_register_id', 'cash_registers.id')),
                'matched'  => $query->whereExists(fn ($q) => $latest($q)->where('tr.status', 'matched')),
                'mismatch' => $query->whereExists(fn ($q) => $latest($q)->where('tr.status', 'mismatch')),
            };
        }

        if (isset($validated['discrepancy'])) {
            $query->whereExists(function ($q) use ($validated) {
                $q->from('till_discrepancies as td')->whereColumn('td.cash_register_id', 'cash_registers.id');
                if ($validated['discrepancy'] !== 'any') {
                    $q->where('td.status', $validated['discrepancy']);
                }
            });
        }

        $page = $query->orderByDesc('opened_at')->orderByDesc('id')->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (CashRegister $r) => $this->presenter->present($r, $user))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $register = $this->visibleOr404($request, $id);

        return response()->json(['till' => $this->presenter->present($register, $request->user(), true)]);
    }

    /**
     * Closing notes, by the till's operator, before it is finalized. Nothing
     * else: money and lifecycle are set only by open / count / finalize, and
     * once finalized not even those.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $register = $this->visibleOr404($request, $id);
        $user     = $request->user();

        $touchesMoney = array_values(array_intersect(array_keys($request->all()), self::MONEY_FIELDS));

        if ($register->isFinalized() || $register->status === 'closed') {
            return response()->json([
                'message' => $register->isFinalized()
                    ? 'This till has been verified and finalized. Its figures can no longer change — finance can open a linked correction instead.'
                    : 'This till was closed before verification existed. It is history and cannot be edited.',
                'code'    => $register->isFinalized() ? 'TILL_FINALIZED' : 'TILL_CLOSED',
            ], 409);
        }

        if ($touchesMoney !== []) {
            return response()->json([
                'message' => 'A till\'s figures are set only by opening it and counting it.',
                'code'    => 'MONEY_FIELDS_NOT_EDITABLE',
                'fields'  => $touchesMoney,
            ], 422);
        }

        $validated = $request->validate(['notes' => 'required|nullable|string|max:500']);

        abort_unless((int) $register->opened_by === (int) $user->id, 403, 'Only the cashier who worked this till can amend its notes.');

        $register->update(['closing_notes' => $validated['notes']]);

        return response()->json(['till' => $this->presenter->present($register->fresh(), $user)]);
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        $register = $this->visibleOr404($request, $id);
        $user     = $request->user();

        abort_unless(
            TillVisibility::mayVerifyAt($user, (int) $register->outlet_id),
            403,
            'Only an outlet manager at this till\'s outlet can verify it.',
        );

        if ($register->isFinalized() || $register->status !== 'counted') {
            return response()->json([
                'message' => $register->status === 'open'
                    ? 'This till is still open. The cashier submits her count first.'
                    : 'This till is not awaiting verification.',
                'code'    => $register->isFinalized() ? 'TILL_FINALIZED' : 'TILL_NOT_COUNTED',
            ], 409);
        }

        // Before any write: the operator never verifies her own count, whatever
        // she holds (super_admin included).
        MakerChecker::assertNotMaker($user, 'till.verify', $register, $register->opened_by, $register->closed_by);

        $hasVariance = abs((float) $register->variance) >= 0.005;
        $validated = $request->validate([
            // Any variance is recorded with its reason; the verifier is the
            // first person allowed to see it.
            'variance_reason' => ($hasVariance ? 'required' : 'nullable') . '|string|min:3|max:1000',
            'notes'           => 'nullable|string|max:1000',
        ], [
            'variance_reason.required' => 'This till is ' . ($register->variance > 0 ? 'over' : 'short') . ' — record why before finalizing.',
        ]);

        [$finalized, $discrepancy] = $this->tills->finalize(
            $register, $user, $validated['variance_reason'] ?? null, $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => $discrepancy?->status === TillDiscrepancy::STATUS_AWAITING_APPROVAL
                ? 'Till finalized. The variance is above ' . (int) TillDiscrepancy::LOGGED_LIMIT . ' and is held for approval.'
                : 'Till verified and finalized.',
            'till'    => $this->presenter->present($finalized, $user, true),
        ]);
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        $register = $this->visibleOr404($request, $id);
        $user     = $request->user();

        if (!$register->isFinalized()) {
            return response()->json([
                'message' => 'Only a verified, finalized till can be reconciled.',
                'code'    => 'TILL_NOT_FINALIZED',
            ], 409);
        }

        // Independent: not the person who counted it, nor the one who verified it.
        MakerChecker::assertNotMaker($user, 'till.reconcile', $register, $register->opened_by, $register->closed_by, $register->verified_by);

        $validated = $request->validate(['notes' => 'nullable|string|max:2000']);

        $rec = $this->tills->reconcile($register, $user, $validated['notes'] ?? null);

        return response()->json([
            'message'        => $rec->status === 'matched'
                ? 'Reconciled: the till agrees with the payments ledger.'
                : 'Mismatch: flagged for finance.',
            'reconciliation' => $this->presenter->reconciliation($rec),
        ], 201);
    }

    public function storeCorrection(Request $request, int $id): JsonResponse
    {
        $register = $this->visibleOr404($request, $id);
        $user     = $request->user();

        if (!$register->isFinalized()) {
            return response()->json([
                'message' => 'Only a finalized till takes a correction. Until then it is still being counted and verified.',
                'code'    => 'TILL_NOT_FINALIZED',
            ], 409);
        }

        MakerChecker::assertNotMaker($user, 'till.correct', $register, $register->opened_by, $register->closed_by);

        $validated = $request->validate([
            'reason'                  => 'required|string|min:10|max:2000',
            'corrected_actual_cash'   => 'nullable|numeric|min:0',
            'corrected_expected_cash' => 'nullable|numeric|min:0',
        ]);

        $correction = $this->tills->openCorrection($register, $user, $validated);

        return response()->json([
            'message'    => 'Correction recorded against the till. The original stays as it was counted.',
            'correction' => $this->presenter->correction($correction),
        ], 201);
    }

    private function visibleOr404(Request $request, int $id): CashRegister
    {
        $register = TillVisibility::scope(CashRegister::query()->whereKey($id), $request->user())->first();
        abort_if($register === null, 404, 'Till not found.');

        return $register;
    }
}
