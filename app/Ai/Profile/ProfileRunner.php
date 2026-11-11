<?php

namespace App\Ai\Profile;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use Closure;

// Sends a fixed prompt set to one provider, streaming, while a sampler watches memory. The first request is a one-token "hello" so the load shows up as its own phase.
final class ProfileRunner
{
    private Closure $clock;

    public function __construct(
        private AiManager $ai,
        private MemorySampler $sampler,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => microtime(true);
    }

    /** @param list<ProfilePrompt> $prompts */
    public function run(string $provider, array $prompts, int $maxTokens = 200): Profile
    {
        $model = $this->ai->chat($provider);

        $startedAt = ($this->clock)();

        $loadedAt = $startedAt;
        $traces = [];

        $this->sampler->start();

        try {
            $model->chat(ChatRequest::make([['role' => 'user', 'content' => 'Reply with one word: ready.']], ['max_tokens' => 1, 'profile' => 'deterministic'], 'ai.profile'));

            $loadedAt = ($this->clock)();

            foreach ($prompts as $prompt) {
                $traces[] = $this->trace($provider, $prompt, $maxTokens);
            }
        } finally {
            $samples = $this->sampler->stop();
        }

        return Timeline::build($startedAt, $loadedAt, $traces, $samples);
    }

    private function trace(string $provider, ProfilePrompt $prompt, int $maxTokens): PromptTrace
    {
        $request = ChatRequest::make(
            [['role' => 'user', 'content' => $prompt->text]],
            ['max_tokens' => $maxTokens, 'profile' => 'deterministic'],
            'ai.profile',
        );

        $sentAt = ($this->clock)();
        $times = [];

        $stream = $this->ai->chat($provider)->stream($request);

        foreach ($stream as $event) {
            if ($event instanceof Delta) {
                $times[] = ($this->clock)();
            }
        }

        $response = $stream->getReturn();

        return new PromptTrace(
            name: $prompt->name,
            sentAt: $sentAt,
            firstDeltaAt: $times[0] ?? ($this->clock)(),
            lastDeltaAt: end($times) ?: ($this->clock)(),
            deltaTimes: $times,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }
}
