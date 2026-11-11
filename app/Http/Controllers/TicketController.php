<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = TicketStatus::tryFrom((string) $request->query('status'));

        $tickets = Ticket::query()
            ->with('customer')
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->orderByDesc('id')
            ->paginate(config('ledgerly.per_page'))
            ->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'status' => $status,
            'statuses' => TicketStatus::cases(),
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load(['customer', 'user', 'triageRun']);

        $invoice = $ticket->invoice_number
            ? Invoice::whereNumber($ticket->invoice_number)->first()
            : null;

        return view('tickets.show', [
            'ticket' => $ticket,
            'invoice' => $invoice,
        ]);
    }
}
