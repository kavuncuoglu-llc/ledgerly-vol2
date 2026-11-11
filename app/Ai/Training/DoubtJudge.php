<?php

namespace App\Ai\Training;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\JudgePrompt;
use App\Facades\Ai;
use Throwable;

// Chapter 40, Topic 6: a judge model can order the review queue so the doubtful examples come first. It never labels: the score decides where an example sits in the queue, and a person's decision is what goes in the dataset.
class DoubtJudge
{
    /**
     * @param  list<array>  $joined
     * @return list<array{id: string, doubt: int, record: array}> most doubtful first
     */
    public function sorted(array $joined, ?string $provider = null): array
    {
        $provider ??= config('ai.tiers.small', 'local');

        $entries = array_map(fn (array $record) => ['id' => $record['id'], 'doubt' => $this->doubt($record, (string) $provider), 'record' => $record], $joined);

        usort($entries, fn (array $a, array $b) => [$b['doubt'], $a['id']] <=> [$a['doubt'], $b['id']]);

        return $entries;
    }

    private function doubt(array $record, string $provider): int
    {
        $question = (string) collect($record['prompt'])->where('role', 'user')->last()['content'];

        $reply = (string) $record['completion'][0]['content'];

        try {
            $score = JudgePrompt::parse((string) Ai::chat($provider)->chat(ChatRequest::make(
                JudgePrompt::messages($question, $reply, []),
                ['profile' => 'deterministic', 'max_tokens' => 4],
                'dataset.triage',
            ))->content);
        } catch (Throwable) {
            return 5;                                                           // a judge that cannot answer makes an example more doubtful, never less
        }

        return 6 - ($score ?? 1);
    }
}
