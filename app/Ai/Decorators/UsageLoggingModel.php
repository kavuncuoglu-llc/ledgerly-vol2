<?php

namespace App\Ai\Decorators;

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Drivers\OllamaNativeShape;
use App\Ai\Events\ModelCalled;
use App\Ai\Http\StreamTruncated;
use App\Ai\Tokens\TokenCounter;
use Generator;

// Every call, from every driver, becomes one ModelCalled event (Chapter 2): written here once, so no feature and no driver logs by hand.
// A non-streamed call has no first delta to time; when the answer came from Ollama's /api/chat the server's own timings give the time to first token (Chapter 22).
final class UsageLoggingModel extends ModelDecorator
{
    public function chat(ChatRequest $request): ChatResponse
    {
        $started = hrtime(true);

        $response = $this->inner->chat($request);

        $callId = $this->record($request, $response->model, $response->usage->toArray(), $response->finishReason->value, $this->since($started), OllamaNativeShape::timeToFirstTokenMs($response->raw), $response->usage->cachedTokens, $response->usage->promptTokens);

        return $response->withCallId($callId);
    }

    public function stream(ChatRequest $request): Generator
    {
        $started = hrtime(true);

        $firstDeltaMs = null;

        $stream = $this->inner->stream($request);

        try {
            foreach ($stream as $event) {
                if ($event instanceof Delta) {
                    $firstDeltaMs ??= $this->since($started);
                }

                yield $event;
            }
        } catch (StreamTruncated $e) {
            $usage = $e->usage ?? ['prompt_tokens' => 0, 'completion_tokens' => 0];

            $this->record($request, $e->model ?? $request->model ?? $this->inner->name(), $usage, 'truncated', $this->since($started), $firstDeltaMs, $usage['prompt_tokens_details']['cached_tokens'] ?? null, (int) ($usage['prompt_tokens'] ?? 0));

            throw $e;
        }

        $response = $stream->getReturn();

        $callId = $this->record($request, $response->model, $response->usage->toArray(), $response->finishReason->value, $this->since($started), $firstDeltaMs, $response->usage->cachedTokens, $response->usage->promptTokens);

        return $response->withCallId($callId);
    }

    // The engine's /completion answers with a string, so there is no usage block to read: count both sides with the app's counter (exact when it is the llama-server one).
    public function completeWithGrammar(string $prompt, string $grammar, int $maxTokens = 4, array $options = [], string $feature = 'grammar'): string
    {
        $started = hrtime(true);

        $text = parent::completeWithGrammar($prompt, $grammar, $maxTokens, $options, $feature);

        $counter = app(TokenCounter::class);

        ModelCalled::dispatch(
            $feature,
            $this->inner->name(),
            $this->innerModel(),
            ['prompt_tokens' => $counter->count($prompt), 'completion_tokens' => $counter->count($text)],
            'stop',
            $this->since($started),
        );

        return $text;
    }

    private function innerModel(): string
    {
        $driver = $this->unwrap();

        return method_exists($driver, 'model')
            ? (string) $driver->model()
            : '';
    }

    private function record(ChatRequest $request, string $model, array $usage, string $finish, int $latencyMs, ?int $firstDeltaMs, ?int $reportedCached, int $promptTokens): ?int
    {
        $event = new ModelCalled(
            $request->feature,
            $this->inner->name(),
            $model,
            $usage,
            $finish,
            $latencyMs,
            $request->meta['conversation_id'] ?? null,
            $request->meta['prompt_version'] ?? null,
            (bool) ($request->meta['repaired'] ?? false),
            $reportedCached ?? $this->estimateCachedTokens($request, $promptTokens),
            $firstDeltaMs,
            $request->meta['context_breakdown'] ?? null,
            $request->meta['semantic_cache_hit'] ?? null,
            $this->recordable($request),
            $request->meta['variant'] ?? null,
        );

        event($event);

        return $event->modelCallId;
    }

    // Off by default. When on, ai:compare --from-calls can replay real requests against other providers; they hold customer text, so they expire (conversations:retain) and are forgotten with the user.
    private function recordable(ChatRequest $request): ?array
    {
        if (! config('ai.debug.record_requests')) {
            return null;
        }

        return array_filter([
            'messages' => $request->messages,
            'options' => $request->options,
            'tools' => $request->tools,
            'response_format' => $request->responseFormat,
            'profile' => $request->profile,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function estimateCachedTokens(ChatRequest $request, int $promptTokens): ?int
    {
        $conversationId = $request->meta['conversation_id'] ?? null;

        if ($conversationId === null) {
            return null;
        }

        $estimate = app(PrefixCacheEstimator::class)->estimate($conversationId, $request->feature, $request->tools, $request->messages);   // no billing signal locally: guess from the shared prefix

        return $promptTokens > 0
            ? min($estimate, $promptTokens)                                      // the server's own count of the prompt is the ceiling
            : $estimate;
    }

    private function since(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
