<?php

namespace App\Mcp\Prompts;

use App\Ai\Prompts\SupportPrompt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('support-prompt')]
#[Description('Ledgerly\'s support assistant prompt (SupportPrompt): the system rules, then the question with its context.')]
final class SupportPromptMcp extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument('question', 'The customer\'s question.', required: true),
        ];
    }

    public function handle(Request $request): array
    {
        $request->validate(['question' => ['required', 'string', 'max:2000']]);

        $messages = SupportPrompt::messages($request->get('question'));

        return array_map(fn (array $message) => Response::text($message['content']), $messages);   // system, the empty context, then the question
    }
}
