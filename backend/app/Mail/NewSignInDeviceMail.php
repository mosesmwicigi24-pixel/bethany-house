<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the account holder: their password was just used from a device not seen before (Phase 4C). */
class NewSignInDeviceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $device,
        public readonly string $ip,
        public readonly ?string $country,
        public readonly string $at,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-in to your Bethany Hub account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.new-sign-in-device', with: [
            'name' => $this->name, 'device' => $this->device, 'ip' => $this->ip,
            'country' => $this->country, 'at' => $this->at,
        ]);
    }
}
