<?php

namespace App\Ai\Tools;

use App\Models\PendingToolCall;

final class ToolResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly ?string $text = null,
        public readonly ?PendingToolCall $pending = null,
        public readonly ?string $approvalUrl = null,
    ) {}

    public static function ok(array $data = []): self
    {
        return new self(ok: true, data: $data);
    }

    public static function text(string $text): self
    {
        return new self(ok: true, text: $text);
    }

    public static function error(string $error, array $details = []): self
    {
        return new self(ok: false, data: $details === [] ? [] : ['details' => $details], error: $error);
    }

    public static function denied(string $reason): self
    {
        return new self(ok: false, error: $reason);
    }

    public static function declined(): self
    {
        return new self(ok: false, error: 'declined by user');
    }

    public static function pendingApproval(PendingToolCall $pending, string $approvalUrl): self
    {
        return new self(ok: false, error: 'awaiting approval', pending: $pending, approvalUrl: $approvalUrl);
    }

    public function isPending(): bool
    {
        return $this->pending !== null;
    }

    public function toArray(): array
    {
        if ($this->text !== null) {
            return ['ok' => true, 'text' => $this->text];
        }

        if ($this->ok) {
            return ['ok' => true] + $this->data;
        }

        return ['ok' => false, 'error' => $this->error] + $this->data;
    }

    public function toJson(): string
    {
        return $this->text ?? json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }
}
