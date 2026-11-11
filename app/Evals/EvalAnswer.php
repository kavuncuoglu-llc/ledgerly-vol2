<?php

namespace App\Evals;

// Chapter 29, Figure 29-9: what POST /eval/answer returns and what every gate reads. Labels are the eval form (help:billing/late-fees#how-the-fee-is-calculated).
final readonly class EvalAnswer
{
    public function __construct(
        public string $answer,
        public string $behavior,
        public array $citations,
        public array $retrieved,
        public int $messageId = 0,
        public int $elapsedMs = 0,
        public string $promptVersion = '',
        public array $sources = [],
        public int $inputTokens = 0,                                   // Chapter 35: the prompt tokens of every model call the answer caused
    ) {}

    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'behavior' => $this->behavior,
            'citations' => $this->citations,
            'retrieved' => $this->retrieved,
            'message_id' => $this->messageId,
            'elapsed_ms' => $this->elapsedMs,
            'prompt_version' => $this->promptVersion,
            'sources' => $this->sources,
            'input_tokens' => $this->inputTokens,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            answer: (string) ($row['answer'] ?? ''),
            behavior: (string) ($row['behavior'] ?? 'answer'),
            citations: $row['citations'] ?? [],
            retrieved: $row['retrieved'] ?? [],
            messageId: (int) ($row['message_id'] ?? 0),
            elapsedMs: (int) ($row['elapsed_ms'] ?? 0),
            promptVersion: (string) ($row['prompt_version'] ?? ''),
            sources: $row['sources'] ?? [],
            inputTokens: (int) ($row['input_tokens'] ?? 0),
        );
    }
}
