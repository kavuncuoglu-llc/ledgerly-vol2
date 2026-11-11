<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

final class TicketReceived
{
    use Dispatchable;

    public readonly string $id;

    public function __construct(public readonly Ticket $ticket, ?string $id = null)
    {
        $this->id = $id ?? (string) Str::uuid();               // an email provider's message id belongs here when there is one: the same id makes a duplicate a no-op
    }
}
