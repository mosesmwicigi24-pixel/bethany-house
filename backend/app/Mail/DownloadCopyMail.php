<?php

namespace App\Mail;

use App\Models\DownloadRequest;
use App\Services\Downloads\DownloadPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Rendered by resources/views/mail/download-copy.blade.php */
class DownloadCopyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DownloadRequest $dr) {}

    public function envelope(): Envelope
    {
        $who = trim(($this->dr->user?->first_name ?? '') . ' ' . ($this->dr->user?->last_name ?? '')) ?: 'Someone';
        return new Envelope(subject: "Download: {$this->dr->label} — {$who} ({$this->dr->export_id})");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.download-copy', with: [
            'dr'        => $this->dr,
            'attached'  => $this->attachable(),
            'consoleUrl'=> rtrim((string) config('audit.console_url'), '/') . '/settings/downloads?view=all&open=' . $this->dr->uuid,
            'never'     => $this->dr->category === DownloadPolicy::NEVER_ATTACH,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (!$this->attachable()) {
            return [];
        }
        return [
            Attachment::fromStorageDisk(config('audit.downloads.archive_disk', 'local'), $this->dr->archive_path)
                ->as($this->dr->file_name ?: ($this->dr->export_id . '.bin'))
                ->withMime($this->dr->content_type ?: 'application/octet-stream'),
        ];
    }

    private function attachable(): bool
    {
        return $this->dr->category !== DownloadPolicy::NEVER_ATTACH
            && $this->dr->archive_path
            && (int) $this->dr->file_bytes > 0
            && (int) $this->dr->file_bytes <= (int) config('audit.downloads.attach_max_bytes', 10485760)
            && Storage::disk(config('audit.downloads.archive_disk', 'local'))->exists($this->dr->archive_path);
    }
}
