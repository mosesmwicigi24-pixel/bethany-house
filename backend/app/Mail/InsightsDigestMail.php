<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 07:30 morning brief (App\Console\Commands\SendInsightsDigest).
 * HTML: resources/views/mail/insights-digest.blade.php. The plain text the
 * brief always had travels beside it, word for word (and stays the WhatsApp
 * message), so a text-only client loses nothing.
 */
class InsightsDigestMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array<string,mixed>>   $items       MetricEngine::attention()
     * @param  list<array<string,string>>  $engineRoom  engine tiles, [] on a quiet day
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly array $items,
        public readonly array $engineRoom,
        public readonly string $plain,
        public readonly string $consoleUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.insights-digest',
            text: 'mail.insights-digest-text',
            with: [
                'items' => $this->items, 'engineRoom' => $this->engineRoom,
                'plain' => $this->plain, 'consoleUrl' => $this->consoleUrl,
                'day' => now(),
            ],
        );
    }
}
