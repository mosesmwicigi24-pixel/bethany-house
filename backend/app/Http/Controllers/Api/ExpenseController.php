<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Expense, ExpenseCategory, ExpenseBudget, ExpenseApproval, ExpenseLineItem, User};
use App\Services\ActivityLogService;
use App\Services\IntelligenceService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Carbon\Carbon;

class ExpenseController extends Controller
{
    public function __construct(
        private ActivityLogService  $activityLog,
        private NotificationService $notifications,
    ) {}

    // =========================================================================
    // EXPENSE CRUD
    // =========================================================================

    /**
     * GET /api/v1/admin/expenses
     * List expenses with filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status'      => 'nullable|in:draft,pending_approval,approved,rejected,paid,cancelled',
            'category_id' => 'nullable|exists:expense_categories,id',
            'outlet_id'   => 'nullable|exists:outlets,id',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'search'      => 'nullable|string|max:100',
            'min_amount'  => 'nullable|numeric|min:0',
            'max_amount'  => 'nullable|numeric',
            'per_page'    => 'nullable|integer|min:5|max:100',
            'sort'        => 'nullable|in:expense_date,amount_kes,created_at,title',
            'direction'   => 'nullable|in:asc,desc',
        ]);

        $query = Expense::with([
            'category:id,name,code,color',
            'outlet:id,name',
            'submittedBy:id,first_name,last_name',
            'approvedBy:id,first_name,last_name',
            'createdBy:id,first_name,last_name',
        ])->withCount('lineItems');

        // Role-scoped access: outlet managers see only expenses for the outlets
        // they are assigned to (via the outlet_user pivot). Users have no
        // `outlet_id` column — assignment is many-to-many — so the previous
        // `$user->outlet_id` was always null and the scoping was broken.
        $user = $request->user();
        $scopeToAssignedOutlets = $user->hasRole('outlet_manager') && !$user->hasAnyRole(['admin', 'super_admin']);
        $assignedOutletIds = $scopeToAssignedOutlets ? $user->outlets()->pluck('outlets.id') : null;
        if ($scopeToAssignedOutlets) {
            $query->whereIn('outlet_id', $assignedOutletIds);
        }

        if (isset($validated['status']))      $query->where('status', $validated['status']);
        if (isset($validated['category_id'])) $query->where('category_id', $validated['category_id']);
        if (isset($validated['outlet_id']))   $query->where('outlet_id', $validated['outlet_id']);
        if (isset($validated['start_date']))  $query->where('expense_date', '>=', $validated['start_date']);
        if (isset($validated['end_date']))    $query->where('expense_date', '<=', $validated['end_date']);
        if (isset($validated['min_amount']))  $query->where('amount_kes', '>=', $validated['min_amount']);
        if (isset($validated['max_amount']))  $query->where('amount_kes', '<=', $validated['max_amount']);

        if (!empty($validated['search'])) {
            $q = $validated['search'];
            $query->where(function ($sq) use ($q) {
                $sq->where('title', 'like', "%{$q}%")
                   ->orWhere('reference_number', 'like', "%{$q}%")
                   ->orWhere('vendor_name', 'like', "%{$q}%")
                   ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $sort      = $validated['sort']      ?? 'expense_date';
        $direction = $validated['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        $perPage = $validated['per_page'] ?? 20;
        $expenses = $query->paginate($perPage);

        // Summary stats for the filtered view
        $statsQuery = Expense::query();
        if ($scopeToAssignedOutlets) {
            $statsQuery->whereIn('outlet_id', $assignedOutletIds);
        }
        $stats = $statsQuery
            ->when(isset($validated['start_date']), fn($q) => $q->where('expense_date', '>=', $validated['start_date']))
            ->when(isset($validated['end_date']),   fn($q) => $q->where('expense_date', '<=', $validated['end_date']))
            ->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN status IN ('approved','paid') THEN amount_kes ELSE 0 END) as approved_total,
                SUM(CASE WHEN status = 'pending_approval' THEN amount_kes ELSE 0 END) as pending_total,
                COUNT(CASE WHEN status = 'pending_approval' THEN 1 END) as pending_count
            ")
            ->first();

        return response()->json([
            'expenses' => $expenses,
            'stats'    => $stats,
        ]);
    }

    /**
     * GET /api/v1/admin/expenses/{id}
     */
    public function show(int $id)
    {
        $expense = Expense::with([
            'category',
            'outlet:id,name',
            'submittedBy:id,first_name,last_name,email',
            'approvedBy:id,first_name,last_name',
            'rejectedBy:id,first_name,last_name',
            'paidBy:id,first_name,last_name',
            'createdBy:id,first_name,last_name',
            'approvals.approver:id,first_name,last_name',
            'lineItems.category:id,name',
            'purchaseOrder:id,po_number',
            'productionOrder:id,order_number',
            'order:id,order_number',
        ])->findOrFail($id);

        return response()->json(['expense' => $expense]);
    }

    /**
     * POST /api/v1/admin/expenses
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string|max:2000',
            'category_id'         => 'required|exists:expense_categories,id',
            'expense_date'        => 'required|date|before_or_equal:today',
            'amount'              => 'required|numeric|min:0.01',
            'currency_code'       => 'required|in:KES,USD,EUR,GBP',
            'exchange_rate'       => 'nullable|numeric|min:0',
            'payment_method'      => 'required|in:cash,bank_transfer,mpesa,card,cheque,other',
            'payment_reference'   => 'nullable|string|max:100',
            'vendor_name'         => 'nullable|string|max:255',
            'vendor_contact'      => 'nullable|string|max:255',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'department'          => 'nullable|string|max:100',
            'is_recurring'        => 'boolean',
            'recurrence_frequency'=> 'nullable|in:weekly,monthly,quarterly,annually',
            'recurrence_end_date' => 'nullable|date|after:expense_date',
            'notes'               => 'nullable|string|max:2000',
            'tags'                => 'nullable|array',
            'tags.*'              => 'string|max:50',
            'purchase_order_id'   => 'nullable|exists:purchase_orders,id',
            'production_order_id' => 'nullable|exists:production_orders,id',
            'order_id'            => 'nullable|exists:orders,id',
            // Line items (optional - for itemized expenses)
            'line_items'          => 'nullable|array',
            'line_items.*.description' => 'required_with:line_items|string',
            'line_items.*.category_id' => 'nullable|exists:expense_categories,id',
            'line_items.*.quantity'    => 'required_with:line_items|numeric|min:0.001',
            'line_items.*.unit_price'  => 'required_with:line_items|numeric|min:0',
            'line_items.*.tax_amount'  => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $user         = $request->user();
            $currencyCode = $validated['currency_code'];
            $exchangeRate = $validated['exchange_rate'] ?? ($currencyCode === 'KES' ? 1.0 : null);

            // Auto-determine exchange rate from system settings if not provided
            if (!$exchangeRate && $currencyCode !== 'KES') {
                $exchangeRate = DB::table('currencies')->where('code', $currencyCode)->value('rate_to_kes') ?? 1.0;
            }

            $amountKes = round($validated['amount'] * $exchangeRate, 2);

            // Determine initial status: needs approval if above category threshold
            $category  = ExpenseCategory::find($validated['category_id']);
            $needsApproval = $category->requires_approval_above !== null
                && $amountKes > $category->requires_approval_above;

            $expense = Expense::create([
                ...$validated,
                'exchange_rate'   => $exchangeRate,
                'amount_kes'      => $amountKes,
                'status'          => $needsApproval ? 'pending_approval' : 'draft',
                'submitted_by'    => $needsApproval ? $user->id : null,
                'submitted_at'    => $needsApproval ? now() : null,
                'created_by'      => $user->id,
            ]);

            // Create line items if provided
            if (!empty($validated['line_items'])) {
                foreach ($validated['line_items'] as $item) {
                    ExpenseLineItem::create([
                        'expense_id'  => $expense->id,
                        'description' => $item['description'],
                        'category_id' => $item['category_id'] ?? $validated['category_id'],
                        'quantity'    => $item['quantity'],
                        'unit_price'  => $item['unit_price'],
                        'amount'      => $item['quantity'] * $item['unit_price'],
                        'tax_amount'  => $item['tax_amount'] ?? 0,
                    ]);
                }
            }

            // Notify finance managers if pending approval
            if ($needsApproval) {
                $financeManagers = User::whereHas('roles.permissions', function ($q) {
                    $q->where('permissions.name', 'expenses.approve')
                      ->where('permissions.guard_name', 'sanctum');
                })->get();
                foreach ($financeManagers as $manager) {
                    $manager->notify(new \App\Notifications\ExpenseApprovalRequiredNotification($expense, $user));
                }
            }

            $this->activityLog->log('expense_created', $expense, [
                'amount'   => $expense->amount_kes,
                'category' => $category->name,
                'status'   => $expense->status,
            ], null, $user);

            DB::commit();

            return response()->json([
                'message' => 'Expense created successfully.',
                'expense' => $expense->fresh(['category', 'outlet', 'createdBy']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create expense: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/v1/admin/expenses/{id}
     */
    public function update(Request $request, int $id)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['draft', 'rejected'])) {
            return response()->json(['message' => 'Only draft or rejected expenses can be edited.'], 422);
        }

        $validated = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'description'         => 'nullable|string|max:2000',
            'category_id'         => 'sometimes|exists:expense_categories,id',
            'expense_date'        => 'sometimes|date|before_or_equal:today',
            'amount'              => 'sometimes|numeric|min:0.01',
            'currency_code'       => 'sometimes|in:KES,USD,EUR,GBP',
            'exchange_rate'       => 'nullable|numeric|min:0',
            'payment_method'      => 'sometimes|in:cash,bank_transfer,mpesa,card,cheque,other',
            'payment_reference'   => 'nullable|string|max:100',
            'vendor_name'         => 'nullable|string|max:255',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'department'          => 'nullable|string|max:100',
            'notes'               => 'nullable|string|max:2000',
            'tags'                => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['amount']) || isset($validated['currency_code'])) {
                $currencyCode = $validated['currency_code'] ?? $expense->currency_code;
                $amount       = $validated['amount']        ?? $expense->amount;
                $exchangeRate = $validated['exchange_rate'] ?? $expense->exchange_rate;

                if (!$exchangeRate && $currencyCode !== 'KES') {
                    $exchangeRate = DB::table('currencies')->where('code', $currencyCode)->value('rate_to_kes') ?? 1.0;
                }

                $validated['amount_kes']    = round($amount * $exchangeRate, 2);
                $validated['exchange_rate'] = $exchangeRate;
            }

            $expense->update($validated);

            $this->activityLog->log('expense_updated', $expense, ['changes' => array_keys($validated)], null, $request->user());

            DB::commit();

            return response()->json([
                'message' => 'Expense updated.',
                'expense' => $expense->fresh(['category', 'outlet']),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/v1/admin/expenses/{id}
     */
    public function destroy(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['draft', 'rejected', 'cancelled'])) {
            return response()->json(['message' => 'Only draft, rejected, or cancelled expenses can be deleted.'], 422);
        }

        $this->activityLog->log('expense_deleted', $expense, ['expense_number' => $expense->reference_number], null, $request->user());
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    // =========================================================================
    // WORKFLOW ACTIONS
    // =========================================================================

    /**
     * POST /api/v1/admin/expenses/{id}/submit
     * Staff submits a draft expense for approval.
     */
    public function submit(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);
        $user    = $request->user();

        if ($expense->status !== 'draft') {
            return response()->json(['message' => 'Only draft expenses can be submitted.'], 422);
        }

        DB::beginTransaction();
        try {
            $expense->update([
                'status'       => 'pending_approval',
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);

            // Notify approvers
            $approvers = User::whereHas('roles.permissions', function ($q) {
                    $q->where('permissions.name', 'expenses.approve')
                      ->where('permissions.guard_name', 'sanctum');
                })->get();
            foreach ($approvers as $approver) {
                $approver->notify(new \App\Notifications\ExpenseApprovalRequiredNotification($expense, $user));
            }

            $this->activityLog->log('expense_submitted', $expense, ['status' => 'pending_approval'], null, $user);
            DB::commit();

            return response()->json(['message' => 'Expense submitted for approval.', 'expense' => $expense->fresh()]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/admin/expenses/{id}/approve
     */
    public function approve(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);
        $user    = $request->user();

        if (!$user->can('expenses.approve')) {
            return response()->json(['message' => 'Forbidden. You do not have permission to approve expenses.'], 403);
        }

        if ($expense->status !== 'pending_approval') {
            return response()->json(['message' => 'Only pending expenses can be approved.'], 422);
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $expense->update([
                'status'      => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            ExpenseApproval::create([
                'expense_id'  => $expense->id,
                'approver_id' => $user->id,
                'action'      => 'approved',
                'comments'    => $validated['comments'] ?? null,
                'acted_at'    => now(),
                'step'        => 1,
            ]);

            // Notify submitter
            if ($expense->submitted_by) {
                $expense->submittedBy->notify(
                    new \App\Notifications\ExpenseApprovalDecisionNotification($expense, 'approved', $validated['comments'] ?? null)
                );
            }

            $this->activityLog->log('expense_approved', $expense, ['comments' => $validated['comments'] ?? null], null, $user);
            DB::commit();

            // Intelligence #6 — check budget warnings after approval and notify if exceeded
            try {
                $warnings = IntelligenceService::expenseBudgetWarnings();
                $exceeded = array_filter($warnings, fn ($w) =>
                    $w['severity'] === 'exceeded' && $w['category_id'] === $expense->category_id
                );
                if (!empty($exceeded)) {
                    $approvers = User::whereHas('roles.permissions', function ($q) {
                        $q->where('permissions.name', 'expenses.approve')
                          ->where('permissions.guard_name', 'sanctum');
                    })->get();
                    $w = reset($exceeded);
                    foreach ($approvers as $approver) {
                        $approver->notify(new \App\Notifications\BudgetExceededNotification(
                            $w['budget_id'],
                            $w['category_name'],
                            $w['budgeted_amount'],
                            $w['actual_spend'],
                            $w['utilization_percent']
                        ));
                    }
                }
            } catch (\Exception) {}

            return response()->json(['message' => 'Expense approved.', 'expense' => $expense->fresh()]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/admin/expenses/{id}/reject
     */
    public function reject(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);
        $user    = $request->user();

        if (!$user->can('expenses.approve')) {
            return response()->json(['message' => 'Forbidden. You do not have permission to reject expenses.'], 403);
        }

        if ($expense->status !== 'pending_approval') {
            return response()->json(['message' => 'Only pending expenses can be rejected.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $expense->update([
                'status'           => 'rejected',
                'rejected_by'      => $user->id,
                'rejected_at'      => now(),
                'rejection_reason' => $validated['reason'],
            ]);

            ExpenseApproval::create([
                'expense_id'  => $expense->id,
                'approver_id' => $user->id,
                'action'      => 'rejected',
                'comments'    => $validated['reason'],
                'acted_at'    => now(),
                'step'        => 1,
            ]);

            if ($expense->submitted_by) {
                $expense->submittedBy->notify(
                    new \App\Notifications\ExpenseApprovalDecisionNotification($expense, 'rejected', $validated['reason'])
                );
            }

            $this->activityLog->log('expense_rejected', $expense, ['reason' => $validated['reason']], null, $user);
            DB::commit();

            return response()->json(['message' => 'Expense rejected.', 'expense' => $expense->fresh()]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/admin/expenses/{id}/mark-paid
     */
    public function markPaid(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);
        $user    = $request->user();

        if (!$user->can('expenses.approve')) {
            return response()->json(['message' => 'Forbidden. You do not have permission to mark expenses as paid.'], 403);
        }

        if ($expense->status !== 'approved') {
            return response()->json(['message' => 'Only approved expenses can be marked as paid.'], 422);
        }

        $validated = $request->validate([
            'payment_reference' => 'nullable|string|max:100',
            'payment_method'    => 'nullable|in:cash,bank_transfer,mpesa,card,cheque,other',
        ]);

        $expense->update([
            'status'            => 'paid',
            'paid_by'           => $user->id,
            'paid_at'           => now(),
            'payment_reference' => $validated['payment_reference'] ?? $expense->payment_reference,
            'payment_method'    => $validated['payment_method']    ?? $expense->payment_method,
        ]);

        $this->activityLog->log('expense_paid', $expense, [
            'payment_reference' => $validated['payment_reference'] ?? $expense->payment_reference,
            'payment_method'    => $validated['payment_method']    ?? $expense->payment_method,
        ], null, $user);

        return response()->json(['message' => 'Expense marked as paid.', 'expense' => $expense->fresh()]);
    }

    /**
     * POST /api/v1/admin/expenses/{id}/cancel
     */
    public function cancel(int $id, Request $request)
    {
        $expense = Expense::findOrFail($id);

        if (in_array($expense->status, ['paid', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel this expense.'], 422);
        }

        $expense->update(['status' => 'cancelled']);
        $this->activityLog->log('expense_cancelled', $expense, ['status' => 'cancelled'], null, $request->user());

        return response()->json(['message' => 'Expense cancelled.', 'expense' => $expense->fresh()]);
    }

    // =========================================================================
    // RECEIPT UPLOAD
    // =========================================================================

    /**
     * POST /api/v1/admin/expenses/{id}/receipt
     */
    public function uploadReceipt(int $id, Request $request)
    {
        $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB
        ]);

        $expense = Expense::findOrFail($id);
        $user    = $request->user();

        // Delete old receipt
        if ($expense->receipt_path) {
            Storage::disk('private')->delete($expense->receipt_path);
        }

        $path = $request->file('receipt')->store("expenses/{$expense->id}/receipts", 'private');
        $expense->update(['receipt_path' => $path]);

        $this->activityLog->log('expense_receipt_uploaded', $expense, ['path' => $path], null, $user);

        return response()->json([
            'message'      => 'Receipt uploaded.',
            'receipt_path' => $path,
        ]);
    }

    /**
     * GET /api/v1/admin/expenses/{id}/receipt
     */
    public function downloadReceipt(int $id)
    {
        $expense = Expense::findOrFail($id);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt attached.'], 404);
        }

        $fullPath = Storage::disk('private')->path($expense->receipt_path);

        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'Receipt file not found.'], 404);
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        $filename = basename($expense->receipt_path);

        return response()->file($fullPath, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    public function categories(Request $request)
    {
        $categories = ExpenseCategory::with('children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn($cat) => array_merge($cat->toArray(), [
                'current_month_spend'       => $cat->currentMonthSpend(),
                'budget_utilization_percent' => $cat->budgetUtilizationPercent(),
            ]));

        return response()->json(['categories' => $categories]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name'                    => 'required|string|max:100|unique:expense_categories,name',
            'code'                    => 'required|string|max:20|unique:expense_categories,code',
            'description'             => 'nullable|string|max:500',
            'parent_id'               => 'nullable|exists:expense_categories,id',
            'color'                   => 'nullable|string|max:7',
            'icon'                    => 'nullable|string|max:50',
            'requires_approval_above' => 'nullable|numeric|min:0',
            'budget_monthly'          => 'nullable|numeric|min:0',
            'budget_annual'           => 'nullable|numeric|min:0',
            'is_tax_deductible'       => 'boolean',
            'gl_code'                 => 'nullable|string|max:20',
        ]);

        $category = ExpenseCategory::create($validated + ['is_active' => true]);

        return response()->json(['message' => 'Category created.', 'category' => $category], 201);
    }

    public function updateCategory(Request $request, int $id)
    {
        $category  = ExpenseCategory::findOrFail($id);
        $validated = $request->validate([
            'name'                    => "sometimes|string|max:100|unique:expense_categories,name,{$id}",
            'code'                    => "sometimes|string|max:20|unique:expense_categories,code,{$id}",
            'description'             => 'nullable|string|max:500',
            'requires_approval_above' => 'nullable|numeric|min:0',
            'budget_monthly'          => 'nullable|numeric|min:0',
            'budget_annual'           => 'nullable|numeric|min:0',
            'is_active'               => 'boolean',
            'is_tax_deductible'       => 'boolean',
            'color'                   => 'nullable|string|max:7',
            'gl_code'                 => 'nullable|string|max:20',
        ]);

        $category->update($validated);

        return response()->json(['message' => 'Category updated.', 'category' => $category->fresh()]);
    }

    // =========================================================================
    // BUDGETS
    // =========================================================================

    public function budgets(Request $request)
    {
        $validated = $request->validate([
            'period_type'   => 'nullable|in:monthly,quarterly,annual',
            'period_year'   => 'nullable|integer|min:2020|max:2100',
            'period_number' => 'nullable|integer|min:1|max:12',
            'outlet_id'     => 'nullable|exists:outlets,id',
        ]);

        $budgets = ExpenseBudget::with('category:id,name,code,color', 'outlet:id,name')
            ->when(isset($validated['period_type']),   fn($q) => $q->where('period_type',   $validated['period_type']))
            ->when(isset($validated['period_year']),   fn($q) => $q->where('period_year',   $validated['period_year']))
            ->when(isset($validated['period_number']), fn($q) => $q->where('period_number', $validated['period_number']))
            ->when(isset($validated['outlet_id']),     fn($q) => $q->where('outlet_id',     $validated['outlet_id']))
            ->get()
            ->map(fn($b) => array_merge($b->toArray(), [
                'actual_spend'         => $b->actualSpend(),
                'variance'             => $b->variance(),
                'utilization_percent'  => $b->utilizationPercent(),
            ]));

        return response()->json(['budgets' => $budgets]);
    }

    public function storeBudget(Request $request)
    {
        $validated = $request->validate([
            'category_id'    => 'required|exists:expense_categories,id',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'period_type'    => 'required|in:monthly,quarterly,annual',
            'period_year'    => 'required|integer|min:2020|max:2100',
            'period_number'  => 'required|integer|min:1|max:12',
            'budgeted_amount'=> 'required|numeric|min:0',
            'currency_code'  => 'required|in:KES,USD',
            'notes'          => 'nullable|string|max:500',
        ]);

        // Prevent duplicates
        $exists = ExpenseBudget::where([
            'category_id'   => $validated['category_id'],
            'outlet_id'     => $validated['outlet_id'] ?? null,
            'period_type'   => $validated['period_type'],
            'period_year'   => $validated['period_year'],
            'period_number' => $validated['period_number'],
        ])->exists();

        if ($exists) {
            return response()->json(['message' => 'A budget for this category and period already exists. Update it instead.'], 422);
        }

        $budget = ExpenseBudget::create($validated + ['created_by' => $request->user()->id]);

        return response()->json(['message' => 'Budget created.', 'budget' => $budget->fresh(['category', 'outlet'])], 201);
    }

    public function updateBudget(Request $request, int $id)
    {
        $budget    = ExpenseBudget::findOrFail($id);
        $validated = $request->validate([
            'budgeted_amount' => 'required|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        $budget->update($validated);

        return response()->json([
            'message' => 'Budget updated.',
            'budget'  => array_merge($budget->fresh(['category', 'outlet'])->toArray(), [
                'actual_spend'        => $budget->actualSpend(),
                'variance'            => $budget->variance(),
                'utilization_percent' => $budget->utilizationPercent(),
            ]),
        ]);
    }

    // =========================================================================
    // ANALYTICS / SUMMARY
    // =========================================================================

    /**
     * GET /api/v1/admin/expenses/summary
     * Dashboard-style analytics for the expense module.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'outlet_id'  => 'nullable|exists:outlets,id',
        ]);

        $start     = $validated['start_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $end       = $validated['end_date']   ?? now()->endOfMonth()->format('Y-m-d');
        $outletId  = $validated['outlet_id']  ?? null;

        $base = Expense::whereIn('status', ['approved', 'paid'])
            ->whereBetween('expense_date', [$start, $end])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));

        // Totals
        $totals = $base->clone()->selectRaw("
            COUNT(*) as total_count,
            SUM(amount_kes) as total_amount,
            AVG(amount_kes) as avg_amount,
            SUM(CASE WHEN status = 'paid' THEN amount_kes ELSE 0 END) as paid_amount,
            SUM(CASE WHEN status = 'approved' THEN amount_kes ELSE 0 END) as approved_unpaid_amount
        ")->first();

        // By category
        $byCategory = $base->clone()
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->selectRaw('
                expense_categories.id,
                expense_categories.name,
                expense_categories.code,
                expense_categories.color,
                COUNT(*) as count,
                SUM(expenses.amount_kes) as total
            ')
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.code', 'expense_categories.color')
            ->orderByDesc('total')
            ->get();

        // Monthly trend (last 12 months)
        $trend = Expense::whereIn('status', ['approved', 'paid'])
            ->where('expense_date', '>=', now()->subMonths(11)->startOfMonth())
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->selectRaw("TO_CHAR(expense_date, 'YYYY-MM') as month, SUM(amount_kes) as total, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Pending for action
        $pending = Expense::where('status', 'pending_approval')
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->selectRaw('COUNT(*) as count, SUM(amount_kes) as total')
            ->first();

        // By payment method
        $byPaymentMethod = $base->clone()
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount_kes) as total')
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'period'             => ['start' => $start, 'end' => $end],
            'totals'             => $totals,
            'by_category'        => $byCategory,
            'monthly_trend'      => $trend,
            'pending'            => $pending,
            'by_payment_method'  => $byPaymentMethod,
        ]);
    }
}