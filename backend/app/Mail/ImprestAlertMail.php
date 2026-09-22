<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the owner: a top-up request, a received amount that differs, a cash count that differs. */
class ImprestAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $heading, public readonly string $body, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.imprest-alert', with: ['heading' => $this->heading, 'body' => $this->body, 'url' => $this->url]);
    }
}
