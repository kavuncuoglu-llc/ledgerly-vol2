<?php

use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schemas\Category;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Support\ClassifyTicket;
use App\Models\ModelCall;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeCategory(string $category): void
{
    Http::fake(['*/chat/completions' => chatReply(ticketJson($category))]);
}

it('returns the Category the model gave', function () {
    fakeCategory('billing');

    expect(app(ClassifyTicket::class)('Why was I charged a late fee on invoice 4471?'))->toBe(Category::Billing);
});

it('asks for TicketData as a json_schema with the category as an enum', function () {
    fakeCategory('howto');

    app(ClassifyTicket::class)('How do I add a logo?');

    Http::assertSent(function (Request $request) {
        $format = $request['response_format'];

        return $request['messages'] === TicketClassifyPrompt::messages('How do I add a logo?')
            && $format['type'] === 'json_schema'
            && $format['json_schema']['name'] === 'ticket_data'
            && $format['json_schema']['strict'] === true
            && $format['json_schema']['schema']['properties']['category']['enum'] === TicketClassifyPrompt::LABELS
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && ! isset($request['prompt_version'])
            && ! isset($request['repaired']);
    });
});

it('repairs a category outside the set and then accepts the fix', function () {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push(['id' => '1', 'model' => 'llama3.1:8b', 'choices' => [['message' => ['content' => ticketJson('billing issue')], 'finish_reason' => 'stop']], 'usage' => []])
        ->push(['id' => '2', 'model' => 'llama3.1:8b', 'choices' => [['message' => ['content' => ticketJson('billing')], 'finish_reason' => 'stop']], 'usage' => []])]);

    expect(app(ClassifyTicket::class)('x'))->toBe(Category::Billing);

    Http::assertSentCount(2);
});

it('raises when the model keeps returning a bad category', function () {
    Http::fake(['*/chat/completions' => chatReply(ticketJson('billing issue'))]);

    app(ClassifyTicket::class)('x');
})->throws(StructuredOutputFailed::class);

it('logs the prompt version on the model_calls row', function () {
    fakeCategory('bug');

    app(ClassifyTicket::class)('Export is broken');

    $call = ModelCall::sole();

    expect($call->feature)->toBe('ticket.classify')
        ->and($call->prompt_version)->toBe(TicketClassifyPrompt::VERSION)
        ->and($call->repaired)->toBeFalse();
});

it('stores the label on a ticket', function () {
    fakeCategory('account');

    $ticket = Ticket::factory()->create(['category' => null, 'body' => 'I cannot sign in', 'subject' => 'Login']);

    $category = app(ClassifyTicket::class)->forTicket($ticket);

    expect($category)->toBe(Category::Account)
        ->and($ticket->fresh()->category)->toBe('account');
});
