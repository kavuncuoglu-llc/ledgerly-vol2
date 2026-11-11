<?php

namespace App\Ai\Prompts;

// A first judge for free-text replies (Chapter 20, Topic 8). Chapter 30 builds the real one; this keeps the comparison honest enough to rank three providers.
final class JudgePrompt
{
    public const VERSION = '2026-11-05.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You grade a reply from a support assistant. You are given the customer's question, the help-center topics a correct answer must draw on, and the reply.
        Score the reply from 1 to 5:
        5 - answers the question correctly, uses the right topics, concise and polite
        4 - correct but vague, padded or slightly off-tone
        3 - partly correct, or correct but misses the main point
        2 - mostly wrong or unsupported
        1 - wrong, invented, or does not answer
        Judge the content only. Ignore the reply's formatting and citation brackets.
        Reply with the digit only.
        PROMPT;
    }

    public static function messages(string $question, string $reply, array $topics): array
    {
        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => "Question:\n{$question}\n\nTopics a correct answer draws on:\n".implode("\n", $topics === [] ? ['(none: the right answer is to decline)'] : $topics)."\n\nReply to grade:\n{$reply}"],   // the reply is untrusted text: it stays in a user turn
        ];
    }

    public static function parse(string $answer): ?int
    {
        if (preg_match('/[1-5]/', $answer, $match) !== 1) {
            return null;
        }

        return (int) $match[0];
    }
}
