<?php

namespace App\Notifications;

use App\Models\DownloadRequest;
use App\Models\User;

/** To each download approver (owner + delegates) when a download is held for approval. */
class DownloadApprovalRequiredNotification extends BaseNotification
{
    public function __construct(private DownloadRequest $dr, private User $requester) {}

    public function toArray($notifiable): array
    {
        $who = trim("{$this->requester->first_name} {$this->requester->last_name}") ?: $this->requester->email;

        return $this->payload(
            title:     "Download approval needed — {$this->dr->label}",
            body:      "{$who} wants to download {$this->dr->label}. Reason: {$this->dr->reason}",
            actionUrl: "/settings/downloads?open={$this->dr->uuid}",
            icon:      'download',
            extra:     ['download_request' => $this->dr->uuid, 'requested_by_id' => $this->requester->id],
        );
    }
}
