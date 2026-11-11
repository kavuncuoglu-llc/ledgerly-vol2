<?php

use App\Ai\Prompts\TicketExtractPrompt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeExtractor(array $overrides = [], array $invalid = []): void
{
    $expectations = collect(ticketSamples())->mapWithKeys(fn (array $s) => [$s['message'] => ['category' => $s['label']] + $s['expect']])->all();

    $seen = [];

    Http::fake(['*/chat/completions' => function (Request $request) use ($expectations, $overrides, $invalid, &$seen) {
        $message = collect($request['messages'])
            ->where('role', 'user')
            ->pluck('content')
            ->map(fn (string $content) => unwrapped($content))
            ->filter(fn (string $content) => isset($expectations[$content]))
            ->last();

        $fields = $overrides[$message] ?? $expectations[$message];

        if (in_array($message, $invalid, true) && ! isset($seen[$message])) {
            $seen[$message] = true;

            return chatReply(json_encode(['category' => 'nonsense'] + $fields));
        }

        return chatReply(json_encode($fields));
    }]);
}

it('prints the same counts the chapter prints', function () {
    fakeExtractor(invalid: ['Invoice shows paid but my bank shows nothing', 'Can I invite my accountant?']);

    $this->artisan('ticket:extract')
        ->expectsOutputToContain('20/20 valid · 2 repaired · 0 failed')
        ->expectsOutputToContain('invoice_number filled: 11   customer_email filled: 4   amount_mentioned filled: 7   date_mentioned filled: 3')
        ->expectsOutputToContain('matches the expected fields exactly: 20/20')
        ->assertSuccessful();
});

it('sends TicketExtractPrompt with the deterministic profile and a TicketData schema', function () {
    fakeExtractor();

    $this->artisan('ticket:extract')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['messages'][0] === ['role' => 'system', 'content' => TicketExtractPrompt::system()]
        && $request['response_format']['json_schema']['name'] === 'ticket_data'
        && $request['temperature'] === 0);
});

it('counts a ticket that fails twice as failed and exits non-zero', function () {
    Sleep::fake();

    Http::fake(['*/chat/completions' => chatReply(json_encode(['category' => 'nonsense', 'invoice_number' => null, 'customer_email' => null, 'amount_mentioned' => null, 'date_mentioned' => null]))]);

    $this->artisan('ticket:extract')
        ->expectsOutputToContain('0/20 valid · 0 repaired · 20 failed')
        ->assertFailed();
});

it('keeps a field that is not in the message null: the model must not guess', function () {
    fakeExtractor(overrides: [
        'Please remove sam@studio.example.com from my team, he left in January' => [
            'category' => 'account', 'invoice_number' => null, 'customer_email' => 'sam@studio.example.com', 'amount_mentioned' => null, 'date_mentioned' => '2026-01-01',
        ],
    ]);

    $this->artisan('ticket:extract')
        ->expectsOutputToContain('date_mentioned filled: 4')
        ->expectsOutputToContain('matches the expected fields exactly: 19/20')
        ->assertSuccessful();
});

it('fails on a missing file and says when the server is down', function () {
    Http::fake();

    $this->artisan('ticket:extract', ['path' => '/no/such/file.json'])->assertFailed();

    Sleep::fake();

    Http::fake(['*/chat/completions' => Http::failedConnection()]);

    $this->artisan('ticket:extract')->expectsOutputToContain('Could not reach the model server')->assertFailed();
});
