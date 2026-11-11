<?php

namespace App\Ai\Compare;

use App\Ai\Contracts\ChatRequest;

// One line of a golden set. Every provider gets the same messages: the request is built once, so a difference in the table is a difference in the model.
final readonly class GoldenCase
{
    public const EXACT = 'exact';

    public const RECALL = 'recall';

    public const REPLAY = 'replay';          // a request recorded from real traffic: no expected answer, so only a judge can score it

    public function __construct(
        public string $id,
        public string $feature,          // support.reply, ticket.classify: the feature the case exercises
        public string $category,         // the axis the by-category table breaks quality down by
        public string $type,             // exact | recall
        public array $input,             // exact: ['message' => ...]; recall: ['question' => ...]
        public array $expected,          // exact: ['label' => ...]; recall: ['articles' => ['billing/late-fees', ...]]
    ) {}

    public static function fromArray(array $line): self
    {
        return new self(
            id: (string) $line['id'],
            feature: (string) $line['feature'],
            category: (string) ($line['category'] ?? 'other'),
            type: (string) $line['type'],
            input: (array) $line['input'],
            expected: (array) $line['expected'],
        );
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'feature' => $this->feature, 'category' => $this->category, 'type' => $this->type, 'input' => $this->input, 'expected' => $this->expected];
    }

    public function withRequest(array $messages, array $options): ChatRequest
    {
        return ChatRequest::make($messages, $options, 'compare.'.$this->feature);   // logged apart from production traffic, so ai:stats stays about customers
    }
}
