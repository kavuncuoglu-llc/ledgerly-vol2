<?php

namespace App\Evals;

use InvalidArgumentException;

// Chapter 29, Topic 4: what must be true of a good answer, never its exact words. Not the Chapter 20 App\Ai\Compare\GoldenCase, which scores recall for ai:compare.
final readonly class GoldenCase
{
    public const BEHAVIORS = ['answer', 'decline', 'escalate', 'refuse'];

    public const SOURCES = ['human', 'synthetic', 'production'];

    public function __construct(
        public string $id,
        public string $category,
        public string $source,
        public string $customer,
        public string $question,
        public string $expectedBehavior,
        public array $expectedChunks = [],
        public array $mustInclude = [],
        public array $mustNotInclude = [],
        public string $referenceAnswer = '',
        public string $added = '2026-11-03',
        public ?string $generator = null,
        public ?string $promptVersion = null,
        public ?string $originCall = null,
    ) {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Golden case {$id}: source must be one of ".implode(', ', self::SOURCES).'.');
        }

        if (! in_array($expectedBehavior, self::BEHAVIORS, true)) {
            throw new InvalidArgumentException("Golden case {$id}: expected_behavior must be one of ".implode(', ', self::BEHAVIORS).'.');
        }
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            category: $row['category'],
            source: $row['source'],
            customer: $row['customer'],
            question: $row['question'],
            expectedBehavior: $row['expected_behavior'],
            expectedChunks: $row['expected_chunks'] ?? [],
            mustInclude: $row['must_include'] ?? [],
            mustNotInclude: $row['must_not_include'] ?? [],
            referenceAnswer: $row['reference_answer'] ?? '',
            added: $row['added'] ?? '2026-11-03',
            generator: $row['generator'] ?? null,
            promptVersion: $row['prompt_version'] ?? null,
            originCall: $row['origin_call'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'category' => $this->category,
            'source' => $this->source,
            'added' => $this->added,
            'generator' => $this->generator,
            'prompt_version' => $this->promptVersion,
            'origin_call' => $this->originCall,
            'customer' => $this->customer,
            'question' => $this->question,
            'expected_behavior' => $this->expectedBehavior,
            'expected_chunks' => $this->expectedChunks,
            'must_include' => $this->mustInclude,
            'must_not_include' => $this->mustNotInclude,
            'reference_answer' => $this->referenceAnswer,
        ], fn ($value) => $value !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
