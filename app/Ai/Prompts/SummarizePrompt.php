<?php

namespace App\Ai\Prompts;

use App\Ai\Conversations\Exchange;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SummarizePrompt
{
    public const VERSION = '2026-11-01.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You maintain a running summary of a support conversation FOR AN AI ASSISTANT that will
        continue the conversation. The turns you are given will be deleted; anything not in your
        summary is lost. Update the existing summary with the new turns.

        Keep, verbatim: invoice numbers, ticket numbers, dates, amounts, names, email addresses.
        Record: the customer's problem, what has been established, what has been tried, what is
        still open. Omit pleasantries. Terse list, not prose. At most 250 words.

        Use these headings, one line each where possible: Problem, Established, Tried, Open.
        Output only the updated summary.
        PROMPT;
    }

    /** @param Collection<int, Exchange> $exchanges */
    public static function messages(?string $existing, Collection $exchanges): array
    {
        $existing = $existing ?: '(none yet)';

        $transcript = $exchanges->map(fn (Exchange $exchange) => self::transcript($exchange))->implode("\n\n");

        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => "Existing summary:\n{$existing}\n\nNew turns to fold in:\n{$transcript}\n\nWrite the updated summary."],
        ];
    }

    private static function transcript(Exchange $exchange): string
    {
        $names = [];

        return $exchange->messages->map(function (Message $message) use (&$names) {
            foreach ($message->tool_calls ?? [] as $call) {
                $names[$call['id'] ?? ''] = $call['function']['name'] ?? 'tool';
            }

            return match ($message->role) {
                'user' => 'Customer: '.$message->content,
                'assistant' => self::assistantLine($message),
                'tool' => 'Tool result ('.($names[$message->tool_call_id] ?? 'tool').'): '.Str::limit((string) $message->content, 1200),
                default => ucfirst($message->role).': '.$message->content,
            };
        })->filter()->implode("\n");
    }

    private static function assistantLine(Message $message): string
    {
        $lines = [];

        foreach ($message->tool_calls ?? [] as $call) {
            $lines[] = 'Assistant called '.($call['function']['name'] ?? 'tool').'('.($call['function']['arguments'] ?? '').')';
        }

        if ($message->content !== null && $message->content !== '') {
            $lines[] = 'Assistant: '.$message->content;
        }

        return implode("\n", $lines);
    }
}
