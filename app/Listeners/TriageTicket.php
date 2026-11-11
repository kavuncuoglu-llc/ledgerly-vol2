<?php

namespace App\Listeners;

use App\Ai\Triage\TriagePipeline;
use App\Events\TicketReceived;
use App\Models\TriageRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;

class TriageTicket implements ShouldQueue
{
    public int $tries = 1;                                                       // a retry would repeat model calls and writes; a failed run is recorded and can be started again

    public function __construct(private TriagePipeline $pipeline) {}

    public function handle(TicketReceived $event): void
    {
        $existing = TriageRun::query()->where('event_id', $event->id)->first();

        if ($existing?->isHandled()) {
            return;                                                              // webhooks retry; email arrives twice
        }

        if ($existing === null && TriageRun::query()->where('ticket_id', $event->ticket->id)->whereIn('status', ['automated', 'human'])->exists()) {
            return;                                                              // a different event for a ticket that was already triaged
        }

        $run = $existing ?? $this->claim($event);

        if ($run === null) {
            return;
        }

        $this->pipeline->run($event->ticket, $run);
    }

    private function claim(TicketReceived $event): ?TriageRun
    {
        try {
            return TriageRun::create(['event_id' => $event->id, 'ticket_id' => $event->ticket->id, 'status' => 'running']);
        } catch (UniqueConstraintViolationException) {
            return null;                                                         // the same event on another worker won the race
        }
    }
}
