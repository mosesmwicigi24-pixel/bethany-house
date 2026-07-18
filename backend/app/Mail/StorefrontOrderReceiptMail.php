<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Order receipt for storefront guest orders.
 * Rendered by: resources/views/mail/storefront-receipt.blade.php
 * Sent from StorefrontCheckoutController::store() when the customer
 * supplied an email address.
 */
class StorefrontOrderReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly ?string $paymentLink,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Bethany House order {$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.storefront-receipt',
            with: [
                'order'       => $this->order->load('items'),
                'paymentLink' => $this->paymentLink,
            ],
        );
    }
}
