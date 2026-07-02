<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * DEPLOY TO: app/Mail/EodReportMail.php
 *
 * Mailable for the nightly EoD consolidated report email.
 * Rendered by: resources/views/mail/eod-report.blade.php
 */
class EodReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $date,
        public readonly array  $reports,    // array of stdClass from SendEodReportEmail::fetchReports()
    ) {}

    public function envelope(): Envelope
    {
        $formatted = \Carbon\Carbon::parse($this->date)->format('l, j F Y');

        return new Envelope(
            subject: "End of Day Report — {$formatted}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.eod-report',
            with: [
                'date'    => $this->date,
                'reports' => $this->reports,
            ],
        );
    }
}