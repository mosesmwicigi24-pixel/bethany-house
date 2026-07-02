<?php

namespace App\Notifications;

use App\Models\Expense;

/**
 * Sent to the expense submitter when their expense is approved or rejected.
 *
 * Recipient: the user who submitted the expense
 * Notifiable: the submitter
 */
class ExpenseApprovalDecisionNotification extends BaseNotification
{
    public function __construct(
        private Expense $expense,
        private string  $decision,  // 'approved' | 'rejected'
        private ?string $comments = null,
    ) {}

    public function toArray($notifiable): array
    {
        $isApproved  = $this->decision === 'approved';
        $reference   = $this->expense->reference_number;
        $amount      = number_format((float) $this->expense->amount_kes, 2);
        $category    = $this->expense->category?->name ?? 'Uncategorised';

        $body = $isApproved
            ? "Your expense {$reference} (KES {$amount} - {$category}) has been approved."
            : "Your expense {$reference} (KES {$amount} - {$category}) was rejected."
              . ($this->comments ? " Reason: {$this->comments}" : '');

        return $this->payload(
            title:     $isApproved
                ? "Expense approved - {$reference}"
                : "Expense rejected - {$reference}",
            body:      $body,
            actionUrl: "/expenses/{$this->expense->id}",
            icon:      'payment',
            extra:     [
                'expense_id'       => $this->expense->id,
                'reference_number' => $reference,
                'amount_kes'       => $this->expense->amount_kes,
                'decision'         => $this->decision,
                'comments'         => $this->comments,
            ],
        );
    }
}