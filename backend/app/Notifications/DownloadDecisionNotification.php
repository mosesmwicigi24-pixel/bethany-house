<?php

namespace App\Notifications;

use App\Models\DownloadRequest;
use App\Models\User;

/** To the requester when their download request is approved or denied. */
class DownloadDecisionNotification extends BaseNotification
{
    public function __construct(private DownloadRequest $dr, private User $approver) {}

    public function toArray($notifiable): array
    {
        $approved = $this->dr->status === DownloadRequest::APPROVED;
        $by = trim("{$this->approver->first_name} {$this->approver->last_name}") ?: 'An approver';

        return $this->payload(
            title:     $approved ? "Download approved — {$this->dr->label}" : "Download not approved — {$this->dr->label}",
            body:      $approved
                ? "{$by} approved it. Open My downloads to take it (valid for 24 hours)."
                : "{$by} did not approve it" . ($this->dr->decision_note ? ": {$this->dr->decision_note}" : '.'),
            actionUrl: '/settings/my-downloads',
            icon:      'download',
            extra:     ['download_request' => $this->dr->uuid, 'approved' => $approved],
        );
    }
}
