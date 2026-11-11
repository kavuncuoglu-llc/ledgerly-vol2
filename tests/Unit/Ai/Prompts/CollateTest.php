<?php

use App\Ai\Prompts\Collate;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\SupportPrompt;

// Chapter 46, Topic 3: Ollama merges consecutive messages of one role before it renders, the Hugging Face template does not. A fine-tuned role is sent one user message, and so are its training rows.
it('merges consecutive user messages with a blank line, as Ollama does, and nothing else', function () {
    $messages = [
        ['role' => 'system', 'content' => 'S'],
        ['role' => 'user', 'content' => 'Passages'],
        ['role' => 'user', 'content' => 'Question'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => '1']]],
        ['role' => 'assistant', 'content' => 'second', 'tool_calls' => [['id' => '2']]],
        ['role' => 'tool', 'content' => 'a', 'tool_call_id' => '1'],
        ['role' => 'tool', 'content' => 'b', 'tool_call_id' => '2'],
        ['role' => 'user', 'content' => 'Thanks'],
    ];

    $collated = Collate::consecutive($messages);

    expect($collated)->toHaveCount(7)
        ->and($collated[1])->toBe(['role' => 'user', 'content' => "Passages\n\nQuestion"])
        ->and(array_column($collated, 'role'))->toBe(['system', 'user', 'assistant', 'assistant', 'tool', 'tool', 'user']);
});

it('leaves a prompt with nothing to merge as it is', function () {
    $messages = [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => 'Q']];

    expect(Collate::consecutive($messages))->toBe($messages)->and(Collate::consecutive([]))->toBe([]);
});

it('sends a fine-tuned role one user message and the base role the messages as they are', function () {
    config(['ai.security.canary' => '']);

    $messages = [['role' => 'system', 'content' => SupportPrompt::base()], ['role' => 'user', 'content' => 'Passages'], ['role' => 'user', 'content' => 'Question']];

    $tuned = SupportPrompt::boundTo($messages, 'local-billing-ft');

    expect($tuned)->toHaveCount(2)
        ->and($tuned[0]['content'])->toBe(FineTunedSupportPrompt::system())
        ->and($tuned[1]['content'])->toBe("Passages\n\nQuestion")
        ->and(SupportPrompt::boundTo($messages, 'local'))->toHaveCount(3);
});
