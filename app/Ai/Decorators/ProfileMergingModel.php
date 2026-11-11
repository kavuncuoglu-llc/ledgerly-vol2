<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use Generator;
use InvalidArgumentException;

// The sampling profile (Chapter 5) is merged into the request's options once, here, so no driver and no feature repeats it. Options the feature set itself win.
final class ProfileMergingModel extends ModelDecorator
{
    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->inner->chat($this->merged($request));
    }

    public function stream(ChatRequest $request): Generator
    {
        return $this->inner->stream($this->merged($request));
    }

    private function merged(ChatRequest $request): ChatRequest
    {
        $name = $request->profile
            ?? (config('ai.features')[$request->feature]['profile'] ?? null)
            ?? 'assistant';

        $profile = config('ai.profiles')[$name] ?? throw new InvalidArgumentException("Unknown sampling profile '{$name}'.");

        return $request->with(options: array_merge($profile, $request->options), profile: $name);
    }
}
