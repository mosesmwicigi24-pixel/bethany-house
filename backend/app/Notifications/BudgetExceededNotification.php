<?php

namespace App\Notifications;

/**
 * Sent when an expense category exceeds its monthly budget.
 * Triggered by:
 *  - RunIntelligenceChecks command (nightly at 07:00)
 *  - ExpenseController::approve() (real-time on each approval)
 */
class BudgetExceededNotification extends BaseNotification
{
    public function __construct(
        private int    $budgetId,
        private string $categoryName,
        private float  $budgetedAmount,
        private float  $actualSpend,
        private float  $utilizationPercent,
    ) {}

    public function toArray($notifiable): array
    {
        $remaining = max(0, $this->budgetedAmount - $this->actualSpend);
        $pct       = round($this->utilizationPercent);

        $title = $pct >= 100
            ? "Budget exceeded — {$this->categoryName}"
            : "Budget warning — {$this->categoryName}";

        $body = $pct >= 100
            ? "{$this->categoryName} has exceeded its monthly budget "
              . "(KES " . number_format($this->actualSpend, 0) . " vs "
              . "KES " . number_format($this->budgetedAmount, 0) . " budget)."
            : "{$this->categoryName} is at {$pct}% of its monthly budget. "
              . "KES " . number_format($remaining, 0) . " remaining.";

        return $this->payload(
            title:     $title,
            body:      $body,
            actionUrl: "/expenses",
            icon:      'payment',
            extra:     [
                'budget_id'           => $this->budgetId,
                'category_name'       => $this->categoryName,
                'budgeted_amount'     => $this->budgetedAmount,
                'actual_spend'        => $this->actualSpend,
                'utilization_percent' => $this->utilizationPercent,
            ]
        );
    }
}