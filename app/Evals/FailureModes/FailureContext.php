<?php

namespace App\Evals\FailureModes;

use App\Evals\CaseResult;
use App\Models\Message;
use App\Models\ToolCall;

// Chapter 38, Topic 8: "the required facts were present in the context" needs the context. It is what the model was shown: the passages it retrieved, and what its tools returned in that conversation. Numbers are written both ways (18.0 and 18.00), because JSON drops the trailing zero and a fact is written with it.
final class FailureContext
{
    public function of(CaseResult $result): string
    {
        $answer = $result->answer;

        if ($answer === null) {
            return '';
        }

        $parts = array_map(fn (array $source) => (string) ($source['text'] ?? ''), $answer->sources);

        foreach ($this->toolResults($answer->messageId) as $toolResult) {
            $parts[] = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            array_push($parts, ...$this->numbersIn($toolResult));
        }

        return implode("\n", $parts);
    }

    public function usedTools(CaseResult $result): bool
    {
        return $result->answer !== null && $this->toolResults($result->answer->messageId) !== [];
    }

    private function toolResults(int $messageId): array
    {
        if ($messageId === 0) {
            return [];
        }

        $conversationId = Message::withoutGlobalScopes()->whereKey($messageId)->value('conversation_id');

        if ($conversationId === null) {
            return [];
        }

        return ToolCall::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('decision', ['allowed', 'approved'])
            ->whereNotNull('result')
            ->pluck('result')
            ->all();
    }

    private function numbersIn(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge(...array_map(fn ($item) => $this->numbersIn($item), array_values($value)) ?: [[]]);
        }

        if (is_int($value)) {
            return [(string) $value];
        }

        if (is_float($value)) {
            return [sprintf('%.2f', $value), (string) $value];
        }

        return [];
    }
}
