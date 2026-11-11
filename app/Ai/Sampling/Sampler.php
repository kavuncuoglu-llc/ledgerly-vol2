<?php

namespace App\Ai\Sampling;

use App\Ai\Local\OllamaNative;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

// One chat call with whatever sampling parameters you give it: the curl from Chapter 0, with the knobs exposed.
final class Sampler
{
    public const API_AUTO = 'auto';

    public const API_OPENAI = 'openai';

    public const API_NATIVE = 'native';

    public function __construct(private OllamaNative $native) {}

    public function chooseApi(SamplingParameters $parameters, string $api = self::API_AUTO): string
    {
        return match ($api) {
            self::API_OPENAI, self::API_NATIVE => $api,
            self::API_AUTO => $parameters->needsNativeApi()
                ? self::API_NATIVE
                : self::API_OPENAI,
            default => throw new InvalidArgumentException("Unknown api '{$api}'. Use auto, openai or native."),
        };
    }

    public function sample(string $prompt, string $model, SamplingParameters $parameters, string $api = self::API_AUTO): SampleResult
    {
        $started = hrtime(true);

        $chosen = $this->chooseApi($parameters, $api);

        $result = $chosen === self::API_NATIVE
            ? $this->viaNative($prompt, $model, $parameters)
            : $this->viaOpenAi($prompt, $model, $parameters);

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return new SampleResult(...$result, latencyMs: $latencyMs);
    }

    private function viaOpenAi(string $prompt, string $model, SamplingParameters $parameters): array
    {
        $body = Http::baseUrl(config('ai.base_url'))
            ->withToken(config('ai.api_key'))
            ->acceptJson()
            ->connectTimeout((int) config('ai.connect_timeout'))
            ->timeout((int) config('ai.timeout'))
            ->post('/chat/completions', array_merge($parameters->openAiBody(), [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => false,
            ]))
            ->throw()
            ->json();

        return [
            'api' => self::API_OPENAI,
            'model' => $body['model'] ?? $model,
            'content' => (string) ($body['choices'][0]['message']['content'] ?? ''),
            'finishReason' => $body['choices'][0]['finish_reason'] ?? 'stop',
            'promptTokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
            'completionTokens' => (int) ($body['usage']['completion_tokens'] ?? 0),
        ];
    }

    private function viaNative(string $prompt, string $model, SamplingParameters $parameters): array
    {
        $body = $this->native->chat($model, [['role' => 'user', 'content' => $prompt]], $parameters->ollamaOptions());

        return [
            'api' => self::API_NATIVE,
            'model' => $body['model'] ?? $model,
            'content' => (string) ($body['message']['content'] ?? ''),
            'finishReason' => $body['done_reason'] ?? 'stop',
            'promptTokens' => (int) ($body['prompt_eval_count'] ?? 0),
            'completionTokens' => (int) ($body['eval_count'] ?? 0),
        ];
    }
}
