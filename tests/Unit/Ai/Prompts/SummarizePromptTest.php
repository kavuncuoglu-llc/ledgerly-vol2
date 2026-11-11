<?php

use App\Ai\Conversations\Exchange;
use App\Ai\Prompts\SummarizePrompt;
use App\Ai\Tokens\TokenCounter;
use App\Models\Message;

function summarizeExchange(): Exchange
{
    return new Exchange(collect([
        new Message(['seq' => 1, 'role' => 'user', 'content' => 'Why is INV-4471 late?', 'tokens' => 8]),
        new Message(['seq' => 2, 'role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471","month":null}']]], 'tokens' => 20]),
        new Message(['seq' => 3, 'role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true,"found":true}', 'tokens' => 10]),
        new Message(['seq' => 4, 'role' => 'assistant', 'content' => 'It is 18 days overdue.', 'tokens' => 8]),
    ]));
}

it('fits a small budget', function () {
    expect(app(TokenCounter::class)->count(SummarizePrompt::system()))->toBeLessThanOrEqual(200);
});

it('is versioned with its text', function () {
    expect(md5(SummarizePrompt::system()))->toBe('318c20015d741317e845934ab3b14e84');   // bump VERSION and this hash together
});

it('tells the model who the summary is for, what to keep verbatim and how long it may be', function () {
    expect(SummarizePrompt::system())
        ->toContain('FOR AN AI ASSISTANT')
        ->toContain('anything not in your')
        ->toContain('invoice numbers, ticket numbers, dates, amounts, names, email addresses')
        ->toContain('Terse list, not prose. At most 250 words.');
});

it('assembles messages as expected', function () {
    expect(SummarizePrompt::messages(existing: 'Problem: late fee on INV-4471.', exchanges: collect([summarizeExchange()])))->toMatchSnapshot();
});

it('says there is no summary yet on the first pass', function () {
    $messages = SummarizePrompt::messages(existing: null, exchanges: collect([summarizeExchange()]));

    expect($messages[1]['content'])->toStartWith("Existing summary:\n(none yet)");
});
