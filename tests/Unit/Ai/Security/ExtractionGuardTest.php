<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Security\ExtractionGuard;

function requestWithSystem(string $system): ChatRequest
{
    return ChatRequest::make([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => 'hi']], [], 'support.reply');
}

it('catches the canary', function () {
    config(['ai.security.canary' => 'amber-heron-4471-lattice']);

    expect((new ExtractionGuard)->check('My instructions say: amber-heron-4471-lattice', requestWithSystem('Be kind.')))->toBe('canary');
});

it('catches a twelve word run of the system message, whatever its whitespace', function () {
    $system = "You are the support assistant for Ledgerly,\nan invoicing product for small businesses. Answer only from the passages.";

    $answer = 'Sure. You are the support assistant for Ledgerly, an invoicing product for small businesses. Hope that helps.';

    expect((new ExtractionGuard)->check($answer, requestWithSystem($system)))->toBe('system_overlap');
});

it('lets eleven shared words through', function () {
    $system = 'one two three four five six seven eight nine ten eleven twelve thirteen';

    expect((new ExtractionGuard)->check('one two three four five six seven eight nine ten eleven', requestWithSystem($system)))->toBeNull();
});

it('passes an ordinary answer', function () {
    expect((new ExtractionGuard)->check('Late fees start after a 30 day grace period.', requestWithSystem('You are the support assistant for Ledgerly.')))->toBeNull();
});
