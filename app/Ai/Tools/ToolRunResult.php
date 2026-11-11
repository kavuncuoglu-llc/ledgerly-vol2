<?php

namespace App\Ai\Tools;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Retrieval\CitationReport;
use App\Models\CachedAnswer;
use App\Models\PendingToolCall;

final readonly class ToolRunResult
{
    /** @param PendingToolCall[] $pendingApprovals */
    public function __construct(
        public ?ChatResponse $response,
        public array $messages,
        public int $rounds,
        public bool $hitCap = false,
        public array $pendingApprovals = [],
        public ?ToolResult $toolResult = null,
        public ?CachedAnswer $cacheHit = null,
        public ?CitationReport $citations = null,
    ) {}

    public function answer(): string
    {
        if ($this->cacheHit !== null) {
            return $this->cacheHit->answer;                               // Chapter 14: no model call happened
        }

        if ($this->pendingApprovals !== []) {
            return 'I need your approval before I can do this: '.$this->pendingApprovals[0]->summary;
        }

        if ($this->response === null) {
            return $this->toolResult?->ok
                ? 'Done.'
                : (string) $this->toolResult?->error;
        }

        return (string) $this->response->content;
    }

    public function withContent(string $content): self
    {
        return new self($this->response?->withContent($content), $this->messages, $this->rounds, $this->hitCap, $this->pendingApprovals, $this->toolResult, $this->cacheHit, $this->citations);
    }

    public function withCitations(CitationReport $report): self
    {
        return new self($this->response, $this->messages, $this->rounds, $this->hitCap, $this->pendingApprovals, $this->toolResult, $this->cacheHit, $report);
    }

    public function truncated(): bool
    {
        return $this->hitCap || $this->response?->finishReason === FinishReason::Length;
    }
}
