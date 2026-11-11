<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Facades\Ai;

beforeEach(function () {
    if (! env('RUN_MODEL_TESTS')) {
        $this->markTestSkipped('Needs a running model server: RUN_MODEL_TESTS=1 vendor/bin/pest --group=model');
    }

    Http::allowStrayRequests();
});

it('returns an allowed label', function (string $msg) {
    $out = Ai::for('ticket.classify')->chat(ChatRequest::make(TicketClassifyPrompt::messages($msg), ['profile' => 'deterministic', 'max_tokens' => 4], 'ticket.classify'))->content;

    expect(trim($out))->toBeIn(TicketClassifyPrompt::LABELS);
})->with(['My card was charged twice', 'How do I add a logo?', 'Export is broken', 'I forgot my password'])->group('model');
