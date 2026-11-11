<?php

namespace App\Evals;

use App\Ai\Prompts\SupportPrompt;
use App\Models\Message;
use App\Models\ToolCall;
use Illuminate\Support\Str;

// Chapter 29, Topic 4: the four behaviors a golden case can expect. The app does not store the word, so it is read off what the reply did: a ticket opened is an escalation, the stock sentences and a few phrases are declines and refusals.
final class EvalBehavior
{
    public static function of(Message $reply): string
    {
        $openedTicket = ToolCall::query()
            ->where('conversation_id', $reply->conversation_id)
            ->where('tool', 'open_ticket')
            ->whereIn('decision', ['allowed', 'approved'])
            ->exists();

        return self::classify((string) $reply->content, $openedTicket);
    }

    public static function classify(string $content, bool $openedTicket = false): string
    {
        if ($openedTicket) {
            return 'escalate';
        }

        $text = Str::lower($content);

        if (self::mentionsAny($text, (array) config('ai.eval.refusal_phrases'))) {
            return 'refuse';
        }

        if ($content === SupportPrompt::escapeHatch()) {
            return 'decline';
        }

        if ($content === SupportPrompt::declineMessage()) {
            return 'decline';
        }

        if ($content === SupportPrompt::truncatedMessage()) {
            return 'decline';
        }

        if (self::mentionsAny($text, (array) config('ai.eval.decline_phrases'))) {
            return 'decline';
        }

        return 'answer';
    }

    private static function mentionsAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, Str::lower($phrase))) {
                return true;
            }
        }

        return false;
    }
}
