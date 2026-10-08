<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use App\Services\ImprestService;
use App\Services\IntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Expenses above their category's requires_approval_above: finance ≤ 50,000 ·
 * + super admin above (KES at the reporting rate). Anti-splitting key: the
 * expense category.
 */
final class ExpenseHandler extends ApprovalHandler
{
    public function event(): string { return 'expense'; }
    public function modelClass(): string { return Expense::class; }
    public function makerCheckerAction(): string { return 'expense.approve'; }

    public function amount(Model $e, ?array $payload = null): ?array
    {
        return [(float) $e->amount, (string) ($e->currency_code ?: 'KES')];
    }

    public function counterparty(Model $e, ?array $payload = null): ?string
    {
        return $e->category_id ? "expense_category:{$e->category_id}" : null;
    }

    public function makerIds(Model $e): array
    {
        return [$e->created_by, $e->submitted_by];
    }

    public function adoptedMaker(Model $e): ?int
    {
        $id = $e->submitted_by ?: $e->created_by;

        return $id ? (int) $id : null;
    }

    public function fingerprint(Model $e, ?array $payload = null): array
    {
        return [
            'id'            => $e->id,
            'amount'        => (float) $e->amount,
            'currency_code' => $e->currency_code,
            'amount_kes'    => (float) $e->amount_kes,
            'category_id'   => $e->category_id,
        ];
    }

    public function isAwaitingApproval(Model $e): bool
    {
        return $e->status === 'pending_approval';
    }

    public function summary(Model $e, ?array $payload = null): array
    {
        $e->loadMissing(['category:id,name', 'outlet:id,name']);

        return [
            'title'     => "Expense {$e->reference_number}: {$e->title}",
            'reference' => $e->reference_number,
            'link'      => "/expenses/{$e->id}",
            'lines'     => array_values(array_filter([
                'Category: ' . ($e->category?->name ?? '—'),
                "{$e->currency_code} " . number_format((float) $e->amount, 2) . ($e->vendor_name ? " to {$e->vendor_name}" : ''),
                $e->outlet ? "Outlet: {$e->outlet->name}" : 'Head office',
                $e->isImprest() ? 'Paid from the imprest' : null,
            ])),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $e, User $finalSigner): void
    {
        $comments = $request->signatures()->latest('id')->value('reason');

        $e->update(['status' => 'approved', 'approved_by' => $finalSigner->id, 'approved_at' => now()]);

        ExpenseApproval::create([
            'expense_id'  => $e->id,
            'approver_id' => $finalSigner->id,
            'action'      => 'approved',
            'comments'    => $comments,
            'acted_at'    => now(),
            'step'        => (int) $request->current_band,
        ]);

        if ($e->submitted_by && $e->submittedBy) {
            $e->submittedBy->notify(new \App\Notifications\ExpenseApprovalDecisionNotification($e, 'approved', $comments));
        }

        ActivityLogService::log('expense_approved', $e, [
            'comments' => $comments, 'approval_request_id' => $request->id,
        ], null, $finalSigner);

        // Intelligence #6 — budget warning once the spend is approved.
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($e) {
            try {
                $exceeded = array_filter(IntelligenceService::expenseBudgetWarnings(), fn ($w) =>
                    $w['severity'] === 'exceeded' && $w['category_id'] === $e->category_id);
                if ($exceeded) {
                    $w = reset($exceeded);
                    User::permission('expenses.approve')->get()->each(fn ($approver) => $approver->notify(
                        new \App\Notifications\BudgetExceededNotification(
                            $w['budget_id'], $w['category_name'], $w['budgeted_amount'], $w['actual_spend'], $w['utilization_percent'],
                        )));
                }
            } catch (\Throwable) {
                // A warning never blocks an approval.
            }
        });
    }

    public function onRejected(ApprovalRequest $request, Model $e, User $signer, string $reason): void
    {
        $e->update([
            'status'           => 'rejected',
            'rejected_by'      => $signer->id,
            'rejected_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        ExpenseApproval::create([
            'expense_id'  => $e->id,
            'approver_id' => $signer->id,
            'action'      => 'rejected',
            'comments'    => $reason,
            'acted_at'    => now(),
            'step'        => (int) $request->current_band,
        ]);

        if ($e->submitted_by && $e->submittedBy) {
            $e->submittedBy->notify(new \App\Notifications\ExpenseApprovalDecisionNotification($e, 'rejected', $reason));
        }

        ActivityLogService::log('expense_rejected', $e, ['reason' => $reason, 'approval_request_id' => $request->id], null, $signer);
        app(ImprestService::class)->flagForResolution($e, $signer, 'rejected');
    }

    public function supportsChangeRequests(): bool
    {
        return true;
    }

    /**
     * Back to its maker with a note — not refused (owner request 2026-10-03).
     * The expense becomes 'changes_requested': editable and submittable like a
     * draft, and never spend (MetricEngine counts approved + paid only).
     * Imprest cash is NOT flagged for return: nothing was refused, the maker
     * is fixing the record (its amount stays locked by update()).
     */
    public function onChangesRequested(ApprovalRequest $request, Model $e, User $by, string $note): void
    {
        $e->update(['status' => 'changes_requested']);

        ExpenseApproval::create([
            'expense_id'  => $e->id,
            'approver_id' => $by->id,
            'action'      => 'changes_requested',
            'comments'    => $note,
            'acted_at'    => now(),
            'step'        => (int) $request->current_band,
        ]);

        ActivityLogService::log('expense_changes_requested', $e, [
            'note' => $note, 'approval_request_id' => $request->id, 'version' => $request->version,
        ], "Changes requested on expense {$e->reference_number}: {$note}", $by);
    }

    public function onExpired(ApprovalRequest $request, Model $e): void
    {
        if ($e->status === 'pending_approval') {
            // Back to the maker as a draft; submitting it again is a new version.
            $e->update(['status' => 'draft']);
        }
    }

    public function reopen(Model $e, User $maker): void
    {
        if (!in_array($e->status, ['draft', 'rejected', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a draft, rejected or sent-back expense can be resubmitted.']);
        }
        $e->update(['status' => 'pending_approval', 'submitted_by' => $maker->id, 'submitted_at' => now()]);
    }
}
