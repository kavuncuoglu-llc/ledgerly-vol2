<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InvoiceCopy extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $number, public readonly float $total) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A copy of invoice {$this->number}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice-copy');
    }
}
