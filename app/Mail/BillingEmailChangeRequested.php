<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class BillingEmailChangeRequested extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $newEmail, public readonly string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm the change of your Ledgerly billing email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing-email-change');
    }
}
