<?php

namespace App\Ai\Prompts;

use App\Ai\Context\AssembledPrompt;
use App\Ai\Context\ContextAssembler;
use App\Ai\Memory\MemoryBlock;
use App\Models\User;

final class SupportPrompt
{
    public const VERSION = '2026-11-01.6';

    public static function system(?User $user = null): string
    {
        $memories = $user
            ? MemoryBlock::render($user)
            : '';

        return self::base().($memories === '' ? '' : "\n\n".$memories);   // stable first: memories ride inside the fixed system message
    }

    public static function base(): string
    {
        return <<<'PROMPT'
        You are the support assistant for Ledgerly, an invoicing product for small businesses.
        Answer questions about using Ledgerly; for anything else, say it is outside what you can help with.

        Rules:
        - Answer directly in plain language. No disclaimers about being an AI.
        - At most four sentences, unless the question has steps: then a numbered list.
        - Plain text only: no headers, bold or bullets.
        - Do not restate the question or end with an offer of further help.
        - State a policy, fee, deadline or account detail only if it is in the provided context or a tool result. The context is the only source of Ledgerly-specific facts.
        - Cite the label of each context passage you rely on, in brackets, after the sentence it supports. Do not combine passages into a claim that neither makes alone.
        - If the customer contradicts the context, say so politely and give the correct information.

        If the answer is not in the context, or depends on the customer's account data, say so in one sentence and offer to open a support ticket. Do not guess.

        Tools: lookup_invoice for a specific invoice or payment (never guess invoice details); list_invoices for several invoices at once; open_ticket when the customer reports a problem you cannot resolve or asks you to log an issue (give them the ticket number).
        PROMPT;
    }

    public static function escapeHatch(): string
    {
        return "I can't find that in the Ledgerly help center, and I won't guess. I can open a support ticket for you if you like.";
    }

    public static function unavailableMessage(): string
    {
        return 'Our assistant is briefly unavailable. You can search the help center in the meantime, or open a ticket and a person will reply.';
    }

    public static function truncatedMessage(): string
    {
        return 'That answer was cut off before it was finished, so I have not shown it. Please ask again, or open a ticket and a person will reply.';
    }

    public static function declineMessage(): string
    {
        return 'That is outside what I can help with here. I can answer questions about using Ledgerly, your invoices and your account.';
    }

    public static function messages(string $question, array $contextChunks = [], array $history = [], ?User $user = null, ?string $summary = null): array
    {
        return self::assemble($question, $contextChunks, $history, $user, $summary)->messages;
    }

    public static function assemble(string $question, array $contextChunks = [], array $history = [], ?User $user = null, ?string $summary = null): AssembledPrompt
    {
        return app(ContextAssembler::class)
            ->system(self::base(), $user ? MemoryBlock::render($user) : '')    // slot 1, fixed
            ->summary($summary)                                                  // slot 2, if any
            ->history($history)                                                  // slot 3, windowed
            ->context($contextChunks)                                            // slot 4, per turn
            ->question($question)                                                // last
            ->build();
    }
}
