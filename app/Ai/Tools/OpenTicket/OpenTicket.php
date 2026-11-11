<?php

namespace App\Ai\Tools\OpenTicket;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Events\TicketReceived;
use App\Models\Ticket;

final class OpenTicket implements Tool
{
    use ToolDefaults;

    public function name(): string
    {
        return 'open_ticket';
    }

    public function arguments(): string
    {
        return OpenTicketArgs::class;
    }

    public function description(): string
    {
        return 'Open a support ticket for the current customer with a short summary of the problem. Returns the ticket number. Use when the customer reports a problem you cannot resolve from the context and tools, or asks you to log an issue. Do not use to answer questions, and do not open a second ticket for the same problem.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteLowRisk;
    }

    public function perConversationLimit(): int
    {
        return 3;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $ticket = Ticket::firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($ctx)],          // a retried round cannot open two
            [
                'customer_id' => $ctx->user->customer_id,
                'user_id' => $ctx->user->id,
                'summary' => $args->summary,
                'invoice_number' => $this->ownInvoiceNumber($args->invoice_number, $ctx),
                'source' => 'assistant',
            ],
        );

        if ($ticket->wasRecentlyCreated && $ctx->triageRunId === null) {
            TicketReceived::dispatch($ticket);                                  // a ticket the triage itself opened is not triaged again
        }

        return ToolResult::ok(['ticket_number' => $ticket->number, 'created' => $ticket->wasRecentlyCreated]);
    }

    private function idempotencyKey(ToolContext $ctx): string
    {
        $scope = $ctx->conversationId ?? 'u'.$ctx->user->id;

        return "{$scope}:{$ctx->toolCallId}";
    }

    private function ownInvoiceNumber(?string $number, ToolContext $ctx): ?string
    {
        if (! $number) {
            return null;
        }

        return $ctx->user->customer->invoices()->whereNumber($number)->value('number');   // scoped: another customer's number is dropped
    }
}
