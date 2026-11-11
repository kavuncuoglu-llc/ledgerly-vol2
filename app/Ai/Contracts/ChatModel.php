<?php

namespace App\Ai\Contracts;

use App\Ai\Contracts\Stream\StreamEvent;
use Generator;

interface ChatModel
{
    public function chat(ChatRequest $request): ChatResponse;

    /** @return Generator<int, StreamEvent, mixed, ChatResponse> yields deltas and tool-call events; the return value is the whole reply */
    public function stream(ChatRequest $request): Generator;

    public function supports(Capability $capability): bool;

    public function name(): string;                    // the configured provider name, e.g. 'local'
}
