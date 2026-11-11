<?php

use App\Ai\Prompts\ExtractMemoriesPrompt;
use App\Ai\Tokens\TokenCounter;
use App\Models\Message;

it('fits a small budget', function () {
    expect(app(TokenCounter::class)->count(ExtractMemoriesPrompt::system()))->toBeLessThanOrEqual(200);
});

it('is versioned with its text', function () {
    expect(md5(ExtractMemoriesPrompt::system()))->toBe('669cbdd2605836d5c8243e147533f05d');   // bump VERSION and this hash together
});

it('limits extraction to what the customer stated', function () {
    expect(ExtractMemoriesPrompt::system())
        ->toContain('Only facts the customer stated directly about themselves')
        ->toContain('No guesses, no inferences')
        ->toContain('nothing about the specific issue discussed')
        ->toContain('Output JSON only');
});

it('assembles messages as expected', function () {
    $messages = ExtractMemoriesPrompt::messages(collect([
        new Message(['seq' => 1, 'role' => 'user', 'content' => "I'm the bookkeeper, not the owner.", 'tokens' => 8]),
        new Message(['seq' => 2, 'role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1']], 'tokens' => 8]),
        new Message(['seq' => 3, 'role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'secret tool output', 'tokens' => 8]),
        new Message(['seq' => 4, 'role' => 'assistant', 'content' => 'Noted.', 'tokens' => 8]),
    ]));

    expect($messages)->toMatchSnapshot()
        ->and($messages[1]['content'])->not->toContain('secret tool output');
});
