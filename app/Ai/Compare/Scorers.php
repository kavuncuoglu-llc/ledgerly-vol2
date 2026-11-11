<?php

namespace App\Ai\Compare;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\JudgePrompt;
use App\Ai\Retrieval\CitationChecker;
use Throwable;

// Exact match for classification, retrieval recall for grounded answers, and (when --judge names a provider) a judge model for the free text. Each returns 0 to 1, or null when it cannot say.
final class Scorers
{
    public function __construct(private AiManager $ai, private CitationChecker $citations, private ?string $judge = null) {}

    public function exact(GoldenCase $case, string $reply): float
    {
        $given = strtolower(trim($reply, " \t\n\r\0\x0B\"'`.*"));

        return $given === strtolower((string) $case->expected['label'])
            ? 1.0
            : 0.0;
    }

    /** @param array<string, object> $labelToChunk the passages the reply was shown, by label */
    public function recall(GoldenCase $case, string $reply, array $labelToChunk): float
    {
        $cited = $this->citations->check($reply, $labelToChunk, requireCitations: false)->cited;

        $expected = array_map(fn (string $article) => "help/{$article}.md", (array) ($case->expected['articles'] ?? []));

        if ($expected === []) {
            return $cited === [] ? 1.0 : 0.0;                                       // nothing to find: the right answer cites nothing
        }

        foreach ($cited as $chunk) {
            if (in_array($chunk->source, $expected, true)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    public function judged(GoldenCase $case, string $reply): ?float
    {
        if ($this->judge === null) {
            return null;
        }

        try {
            $answer = $this->ai->chat($this->judge)->chat(ChatRequest::make(
                JudgePrompt::messages((string) ($case->input['question'] ?? ''), $reply, (array) ($case->expected['headings'] ?? $case->expected['articles'] ?? [])),
                ['profile' => 'deterministic', 'max_tokens' => 3, 'prompt_version' => JudgePrompt::VERSION],
                'compare.judge',
            ))->content;
        } catch (Throwable) {
            return null;                                                           // a judge that is down leaves the recall score standing
        }

        $grade = JudgePrompt::parse((string) $answer);

        return $grade === null
            ? null
            : ($grade - 1) / 4;
    }
}
