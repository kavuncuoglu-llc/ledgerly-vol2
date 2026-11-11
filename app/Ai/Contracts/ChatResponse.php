<?php

namespace App\Ai\Contracts;

final readonly class ChatResponse
{
    public function __construct(
        public string $id,
        public string $model,
        public ?string $content,
        public array $toolCalls,           // [] unless the model called tools
        public FinishReason $finishReason,
        public Usage $usage,
        public array $raw,                 // the provider's own body, for when something breaks (Chapter 19, Topic 7)
        public ?int $callId = null,        // the model_calls row UsageLoggingModel wrote for this call (Chapters 30 and 31)
    ) {}

    public function withContent(string $content): self
    {
        return new self($this->id, $this->model, $content, $this->toolCalls, $this->finishReason, $this->usage, $this->raw, $this->callId);
    }

    public function withCallId(?int $callId): self
    {
        return new self($this->id, $this->model, $this->content, $this->toolCalls, $this->finishReason, $this->usage, $this->raw, $callId);
    }

    public function withFinishReason(FinishReason $reason): self
    {
        return new self($this->id, $this->model, $this->content, $this->toolCalls, $reason, $this->usage, $this->raw, $this->callId);
    }

    public function wasCutOff(): bool
    {
        return $this->finishReason === FinishReason::Length;
    }
}
