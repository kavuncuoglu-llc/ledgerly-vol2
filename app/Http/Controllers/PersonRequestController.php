<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonRequest;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

// Chapter 51, Topic 4: the way to a person that every assistant page keeps in view. It opens a ticket in the customer's own name and is never triaged by a model: a person reads it.
class PersonRequestController extends Controller
{
    public function show(): View
    {
        return view('support.person');
    }

    public function store(PersonRequest $request): RedirectResponse
    {
        $user = $request->user();

        $ticket = Ticket::firstOrCreate(
            ['idempotency_key' => 'person:'.$user->id.':'.hash('sha256', $request->validated('note'))],      // the same note sent twice is one request
            [
                'customer_id' => $user->customer_id,
                'user_id' => $user->id,
                'subject' => 'A customer asked for a person',
                'body' => $request->validated('note'),
                'source' => 'person_request',
            ],
        );

        return redirect()->route('support.person')->with('ticket', $ticket->number);
    }
}
