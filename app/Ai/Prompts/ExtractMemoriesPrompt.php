<?php

namespace App\Ai\Prompts;

use App\Models\Message;
use Illuminate\Support\Collection;

final class ExtractMemoriesPrompt
{
    public const VERSION = '2026-11-01.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        From this finished support conversation, list facts ABOUT THE CUSTOMER that would help an
        assistant in a FUTURE conversation. Only facts the customer stated directly about themselves,
        their role, their preferences, or their business setup. No guesses, no inferences about
        mood or intent, nothing about the specific issue discussed.

        Use short snake_case keys such as role, preferred_name, timezone, billing_contact.
        Output JSON only: [{"key": "...", "value": "...", "confidence": 0.0 to 1.0}].
        If there is nothing to remember, output [].
        PROMPT;
    }

    /** @param Collection<int, Message> $messages */
    public static function messages(Collection $messages): array
    {
        $transcript = $messages
            ->whereIn('role', ['user', 'assistant'])
            ->filter(fn (Message $message) => $message->content !== null && $message->content !== '')
            ->map(fn (Message $message) => ($message->role === 'user' ? 'Customer: ' : 'Assistant: ').$message->content)
            ->implode("\n");

        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => "Conversation:\n{$transcript}"],
        ];
    }
}
