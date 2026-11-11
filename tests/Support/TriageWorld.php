<?php

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// One fake chat server for the whole triage pipeline: it tells the four prompts apart by their system message.
// $script keys: votes (message needle => labels voted in turn), extract (message needle => json), steps (agent replies in order), draft (text)
function triageWorld(array $script = []): void
{
    $votes = [];
    $agentCalls = 0;

    Http::fake([
        '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        '*/chat/completions' => function (Request $request) use ($script, &$votes, &$agentCalls) {
            $messages = $request['messages'];
            $system = (string) ($messages[0]['content'] ?? '');
            $last = (string) end($messages)['content'];

            if (str_starts_with($system, 'You classify Ledgerly support messages')) {
                foreach ($script['votes'] ?? [] as $needle => $labels) {
                    if (str_contains($last, $needle)) {
                        $votes[$needle] = ($votes[$needle] ?? -1) + 1;

                        return Http::response(chatBody(ticketJson($labels[min($votes[$needle], count($labels) - 1)])));
                    }
                }

                return Http::response(chatBody(ticketJson($script['default_label'] ?? 'howto')));
            }

            if (str_starts_with($system, 'You extract structured data')) {
                foreach ($script['extract'] ?? [] as $needle => $fields) {
                    if (str_contains($last, $needle)) {
                        return Http::response(chatBody(json_encode($fields + ['category' => 'billing', 'invoice_number' => null, 'customer_email' => null, 'amount_mentioned' => null, 'date_mentioned' => null])));
                    }
                }

                return Http::response(chatBody(ticketJson($script['default_label'] ?? 'howto')));
            }

            if (str_starts_with($system, 'You are gathering facts')) {
                $step = $script['steps'][$agentCalls] ?? chatBody('Nothing more to look up.');

                $agentCalls++;

                return Http::response($step);
            }

            if (str_starts_with($system, 'You draft the first reply')) {
                return Http::response(chatBody($script['draft'] ?? 'A specialist will follow up on this ticket.'));
            }

            return Http::response(chatBody($script['answer'] ?? 'OK.'));
        },
    ]);
}

function triageTicket(array $attributes = [], ?Customer $customer = null): Ticket
{
    $customer ??= Customer::factory()->create(['plan' => 'pro']);

    $owner = $customer->owner ?? User::factory()->owner($customer)->create();

    return Ticket::create($attributes + [
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'subject' => 'Payment shows unpaid',
        'body' => 'My client paid but invoice 4471 still shows unpaid',
        'source' => 'form',
    ]);
}
