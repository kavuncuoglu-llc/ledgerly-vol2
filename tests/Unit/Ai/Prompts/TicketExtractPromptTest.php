<?php

use App\Ai\Prompts\TicketExtractPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Tokens\TokenCounter;

it('fits the budget', function () {
    expect(app(TokenCounter::class)->count(TicketExtractPrompt::system()))->toBeLessThanOrEqual(300);
});

it('is versioned with its text', function () {
    expect(md5(TicketExtractPrompt::system()))->toBe('0707d3185889d8ce604d128228eb617c');   // bump VERSION and this hash together
});

it('assembles messages as expected', function () {
    expect(TicketExtractPrompt::messages('x'))->toMatchSnapshot();
});

it('puts the message to extract from last, in a user turn', function () {
    $messages = TicketExtractPrompt::messages('Invoice 4471 is wrong');

    expect(end($messages))->toBe(['role' => 'user', 'content' => 'Invoice 4471 is wrong']);
});

it('shows examples that are valid TicketData', function () {
    $answers = collect(TicketExtractPrompt::messages('x'))->where('role', 'assistant')->pluck('content');

    foreach ($answers as $answer) {
        $validator = Validator::make(json_decode($answer, true), TicketData::rules());

        expect($validator->passes())->toBeTrue();
    }
});
