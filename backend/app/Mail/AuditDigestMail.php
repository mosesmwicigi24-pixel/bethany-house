<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The owner's morning audit digest. Rendered by resources/views/mail/audit-digest.blade.php */
class AuditDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        $d = $this->data;
        $alert = ($d['integrity']?->event ?? null) === 'audit_verification_failed' ? '⚠ INTEGRITY CHECK FAILED — ' : '';
        $n = $d['downloads']->count();
        return new Envelope(subject: $alert . 'Bethany Hub — ' . $d['day']->format('D j M')
            . " — {$n} download" . ($n === 1 ? '' : 's') . ', ' . $d['exempt']->count() . ' customer documents'
            . ($d['pending']->count() ? ', ' . $d['pending']->count() . ' waiting for you' : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.audit-digest', with: $this->data);
    }
}
