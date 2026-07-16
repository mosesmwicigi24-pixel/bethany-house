<?php

namespace App\Notifications;

/**
 * Someone commented on, or acknowledged, an EoD report.
 *
 * This is what stops the reports page becoming a second inbox nobody opens: the
 * reply is pushed to where the person already looks, rather than waiting to be
 * discovered.
 */
class EodReportRepliedNotification extends BaseNotification
{
    public function __construct(
        private int $reportId,
        private string $authorName,
        private string $reportDate,
        private string $excerpt,
        private bool $acknowledgedOnly = false
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title: $this->acknowledgedOnly
                ? "Your report was acknowledged"
                : "{$this->authorName} replied to a report",
            body: $this->acknowledgedOnly
                ? "{$this->authorName} read your {$this->reportDate} report."
                : $this->excerpt,
            // The EoD list opens the detail drawer for ?report=<id>.
            actionUrl: "/pos/eod-reports?report={$this->reportId}",
            icon:      'reports',
            extra:     ['eod_report_id' => $this->reportId]
        );
    }
}
