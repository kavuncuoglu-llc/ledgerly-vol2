<?php

namespace App\Mcp\Prompts;

use App\Ai\Prompts\TicketClassifyPrompt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('ticket-classify-prompt')]
#[Description('Ledgerly\'s ticket classification prompt (TicketClassifyPrompt): the category rules, then the message to classify.')]
final class TicketClassifyPromptMcp extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument('message', 'The support message to classify.', required: true),
        ];
    }

    public function handle(Request $request): array
    {
        $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return [
            Response::text(TicketClassifyPrompt::system()),
            Response::text($request->get('message')),
        ];
    }
}
