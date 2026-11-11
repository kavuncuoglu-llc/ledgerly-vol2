<?php

namespace App\Ai\Prompts;

use App\Ai\Context\ContextBlock;

final class DraftReplyPrompt
{
    public const VERSION = '2026-11-03.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You draft the first reply to a Ledgerly support ticket. A support agent reads and edits it before anything is sent.
        Rules:
        - Three to five sentences of plain text, in the voice of Ledgerly support. No headers, no bullets.
        - State a policy, fee or deadline only if it is in the help-center passages. Cite the label of each passage you rely on, in brackets, after the sentence it supports.
        - State account facts only from the findings. If the findings and passages do not answer the ticket, say a specialist will follow up.
        - Never promise a refund, a waived fee or a date.
        PROMPT;
    }

    public static function messages(string $ticket, array $fields, array $findings, array $chunks): array
    {
        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => ContextBlock::render($chunks)],
            ['role' => 'user', 'content' => 'Findings about the account: '.($findings === [] ? 'none' : json_encode($findings, JSON_UNESCAPED_SLASHES))."\nFields from the ticket: ".json_encode($fields, JSON_UNESCAPED_SLASHES)."\n\nTicket:\n".$ticket],    // untrusted input stays in user turns
        ];
    }
}
