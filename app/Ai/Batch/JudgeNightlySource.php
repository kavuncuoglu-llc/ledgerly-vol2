<?php

namespace App\Ai\Batch;

use App\Ai\Prompts\JudgePrompt;
use App\Evals\GoldenSet;
use App\Models\Message;
use InvalidArgumentException;

// Chapter 37, Topic 7: the nightly judge, as requests. `golden:all` grades each golden case's reference answer (the judge's own calibration set); `replies:1d` grades the assistant's real replies of the last day against the topics they were shown.
final class JudgeNightlySource implements BatchSource
{
    public function __construct(private GoldenSet $golden) {}

    public function requests(string $spec): iterable
    {
        if ($spec === 'golden:all') {
            return $this->golden();
        }

        if (preg_match('/^replies:(\d+)d$/', $spec, $match) === 1) {
            return $this->replies((int) $match[1]);
        }

        throw new InvalidArgumentException("The judge reads golden:all or replies:Nd, not [{$spec}].");
    }

    private function golden(): iterable
    {
        foreach ($this->golden->all() as $case) {
            if ($case->referenceAnswer === '') {
                continue;
            }

            yield ['custom_id' => 'golden:'.$case->id, 'messages' => JudgePrompt::messages($case->question, $case->referenceAnswer, $case->expectedChunks), 'max_tokens' => 4];
        }
    }

    private function replies(int $days): iterable
    {
        $replies = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->where('status', 'completed')->where('created_at', '>=', now()->subDays($days))->orderBy('id')->cursor();

        foreach ($replies as $reply) {
            yield ['custom_id' => 'reply:'.$reply->id, 'messages' => JudgePrompt::messages((string) $reply->question, (string) $reply->content, $reply->retrievedLabels()), 'max_tokens' => 4];
        }
    }
}
