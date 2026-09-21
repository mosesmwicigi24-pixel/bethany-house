<?php

namespace App\Mail;

use App\Models\DownloadRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the owner when a download is waiting for approval. Rendered by mail/download-approval-request. */
class DownloadApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DownloadRequest $dr) {}

    public function envelope(): Envelope
    {
        $who = trim(($this->dr->user?->first_name ?? '') . ' ' . ($this->dr->user?->last_name ?? '')) ?: 'Someone';
        return new Envelope(subject: "Approve download? {$this->dr->label} — {$who}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.download-approval-request', with: [
            'dr'  => $this->dr,
            'url' => rtrim((string) config('audit.console_url'), '/') . '/settings/downloads?open=' . $this->dr->uuid,
        ]);
    }
}
