<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-time code for the storefront "Find my orders" lookup.
 * Rendered by: resources/views/mail/storefront-otp.blade.php
 * Sent by StorefrontOtpService as the email channel / WhatsApp fallback.
 */
class StorefrontOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $expiresMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->code} is your Bethany House code",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.storefront-otp',
            with: [
                'code'           => $this->code,
                'expiresMinutes' => $this->expiresMinutes,
            ],
        );
    }
}
