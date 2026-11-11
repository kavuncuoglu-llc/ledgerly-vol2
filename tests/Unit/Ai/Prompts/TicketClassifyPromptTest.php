<?php

use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Security\Untrusted;
use App\Ai\Tokens\TokenCounter;

it('fits the budget', function () {
    expect(app(TokenCounter::class)->count(TicketClassifyPrompt::system()))->toBeLessThanOrEqual(300);
});

it('is versioned with its text', function () {
    expect(md5(TicketClassifyPrompt::system()))->toBe('46d6cd35895eaf1e81b4c4ffdf3649e8');   // bump VERSION and this hash together
});

it('assembles messages as expected', function () {
    expect(TicketClassifyPrompt::messages('x'))->toMatchSnapshot();
});

it('has a label for every category in the system text and an example that declines', function () {
    foreach (TicketClassifyPrompt::LABELS as $label) {
        expect(TicketClassifyPrompt::system())->toContain($label);
    }

    $answers = collect(TicketClassifyPrompt::messages('x'))->where('role', 'assistant')->pluck('content');

    expect($answers->all())->toContain('other')
        ->and($answers->every(fn ($answer) => in_array($answer, TicketClassifyPrompt::LABELS, true)))->toBeTrue();
});

it('puts the message to classify last, in a user turn', function () {
    $messages = TicketClassifyPrompt::messages('My card was charged twice');

    expect(end($messages))->toBe(['role' => 'user', 'content' => Untrusted::wrap('My card was charged twice', 'ticket')]);
});
