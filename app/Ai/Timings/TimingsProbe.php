<?php

namespace App\Ai\Timings;

use App\Ai\Local\OllamaNative;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class TimingsProbe
{
    public const API_AUTO = 'auto';

    public const API_NATIVE = 'native';

    public const API_OPENAI = 'openai';

    public function __construct(private OllamaNative $native) {}

    public function run(string $prompt, string $model, string $api = self::API_AUTO): TimingsResult
    {
        return match ($api) {
            self::API_NATIVE => $this->viaNative($prompt, $model),
            self::API_OPENAI => $this->viaOpenAi($prompt, $model),
            self::API_AUTO => $this->viaNativeOrFallBack($prompt, $model),
            default => throw new InvalidArgumentException("Unknown api '{$api}'. Use auto, native or openai."),
        };
    }

    private function viaNativeOrFallBack(string $prompt, string $model): TimingsResult
    {
        try {
            return $this->viaNative($prompt, $model);
        } catch (RequestException $e) {
            if (! in_array($e->response->status(), [404, 405], true)) {
                throw $e;
            }

            return $this->viaOpenAi($prompt, $model);
        }
    }

    private function viaNative(string $prompt, string $model): TimingsResult
    {
        $body = $this->native->chat($model, [['role' => 'user', 'content' => $prompt]]);

        return new TimingsResult(
            api: self::API_NATIVE,
            model: $body['model'] ?? $model,
            content: $body['message']['content'] ?? '',
            finishReason: $body['done_reason'] ?? 'stop',
            timings: ModelTimings::fromOllama($body),
        );
    }

    private function viaOpenAi(string $prompt, string $model): TimingsResult
    {
        $started = hrtime(true);

        $body = Http::baseUrl(config('ai.base_url'))
            ->withToken(config('ai.api_key'))
            ->acceptJson()
            ->connectTimeout((int) config('ai.connect_timeout'))
            ->timeout((int) config('ai.timeout'))
            ->post('/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => false,
            ])
            ->throw()
            ->json();

        $wallNs = hrtime(true) - $started;

        return new TimingsResult(
            api: self::API_OPENAI,
            model: $body['model'] ?? $model,
            content: $body['choices'][0]['message']['content'] ?? '',
            finishReason: $body['choices'][0]['finish_reason'] ?? 'stop',
            timings: ModelTimings::fromUsage($body, $wallNs),
        );
    }
}
