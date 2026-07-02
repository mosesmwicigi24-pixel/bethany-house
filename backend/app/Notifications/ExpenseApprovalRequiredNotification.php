<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Models\User;

/**
 * Sent to all users with expenses.approve permission when:
 *  - A new expense is created above the category's approval threshold
 *  - A staff member manually submits a draft expense for approval
 *
 * Recipients: all users with the expenses.approve permission
 * Notifiable: the approver (manager/admin)
 */
class ExpenseApprovalRequiredNotification extends BaseNotification
{
    public function __construct(
        private Expense $expense,
        private User    $submittedBy,
    ) {}

    public function toArray($notifiable): array
    {
        $submitterName = trim(
            "{$this->submittedBy->first_name} {$this->submittedBy->last_name}"
        ) ?: $this->submittedBy->email;

        $amount      = number_format((float) $this->expense->amount_kes, 2);
        $category    = $this->expense->category?->name ?? 'Uncategorised';
        $reference   = $this->expense->reference_number;
        $outlet      = $this->expense->outlet?->name ?? 'Company-wide';

        return $this->payload(
            title:     "Expense approval required - {$reference}",
            body:      "{$submitterName} submitted an expense of KES {$amount} "
                     . "({$category}, {$outlet}) that requires your approval.",
            actionUrl: "/expenses/{$this->expense->id}",
            icon:      'payment',
            extra:     [
                'expense_id'        => $this->expense->id,
                'reference_number'  => $reference,
                'amount_kes'        => $this->expense->amount_kes,
                'category'          => $category,
                'outlet'            => $outlet,
                'submitted_by_id'   => $this->submittedBy->id,
                'submitted_by_name' => $submitterName,
            ],
        );
    }
}