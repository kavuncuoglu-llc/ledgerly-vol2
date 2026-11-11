<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use stdClass;

// Chapter 22, Topic 5: the same chat request in Ollama's own /api/chat shape, and its answer back in the shape every other provider returns.
// The driver drops to /api/ for exactly the settings /v1/ cannot carry (num_ctx, keep_alive) and for the exact counts and timings only /api/ reports.
final class OllamaNativeShape
{
    private const SAMPLING = ['temperature', 'top_p', 'top_k', 'min_p', 'seed', 'repeat_penalty', 'presence_penalty', 'frequency_penalty', 'stop'];

    /** @param array $body what the driver would have sent to /chat/completions */
    public static function request(array $body, ?int $numCtx, ?string $keepAlive, bool $stream): array
    {
        $options = array_intersect_key($body, array_flip(self::SAMPLING));

        if (isset($body['max_tokens'])) {
            $options['num_predict'] = $body['max_tokens'];
        }

        if ($numCtx !== null) {
            $options['num_ctx'] = $numCtx;
        }

        $native = [
            'model' => $body['model'],
            'messages' => array_map(fn (array $message) => self::message($message), $body['messages']),
            'stream' => $stream,
        ];

        if ($options !== []) {
            $native['options'] = $options;
        }

        if ($keepAlive !== null) {
            $native['keep_alive'] = ctype_digit($keepAlive) || $keepAlive === '-1'
                ? (int) $keepAlive
                : $keepAlive;                                                // "30m" is a string, -1 (pin it) is a number
        }

        if (! empty($body['tools'])) {
            $native['tools'] = $body['tools'];
        }

        $format = self::format($body['response_format'] ?? null);

        if ($format !== null) {
            $native['format'] = $format;
        }

        return $native;
    }

    public static function response(array $native): ChatResponse
    {
        $message = $native['message'] ?? [];
        $toolCalls = self::toolCalls($message['tool_calls'] ?? []);

        return new ChatResponse(
            id: '',
            model: (string) ($native['model'] ?? ''),
            content: $toolCalls !== [] && ($message['content'] ?? '') === ''
                ? null
                : ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            finishReason: $toolCalls !== []
                ? FinishReason::ToolCalls
                : FinishReason::fromOpenAi($native['done_reason'] ?? 'stop'),
            usage: new Usage((int) ($native['prompt_eval_count'] ?? 0), (int) ($native['eval_count'] ?? 0)),
            raw: $native,
        );
    }

    // Milliseconds until the first token, measured by the server: loading the model plus reading the prompt (Chapter 3). Null when the response carries no timings.
    public static function timeToFirstTokenMs(array $native): ?int
    {
        if (! isset($native['prompt_eval_duration'])) {
            return null;
        }

        return (int) round((((float) ($native['load_duration'] ?? 0)) + (float) $native['prompt_eval_duration']) / 1_000_000);
    }

    public static function toolCalls(array $calls): array
    {
        $shaped = [];

        foreach (array_values($calls) as $index => $call) {
            $arguments = $call['function']['arguments'] ?? [];

            $shaped[] = [
                'id' => $call['id'] ?? 'call_'.substr(md5(($call['function']['name'] ?? '').json_encode($arguments).$index), 0, 12),
                'type' => 'function',
                'function' => [
                    'name' => $call['function']['name'] ?? '',
                    'arguments' => is_string($arguments)
                        ? $arguments
                        : json_encode($arguments === [] ? new stdClass : $arguments),
                ],
            ];
        }

        return $shaped;
    }

    // OpenAI keeps tool-call arguments as a JSON string and an image as a content part; Ollama keeps arguments as an object and images as a list of base64 strings.
    private static function message(array $message): array
    {
        if (is_array($message['content'] ?? null)) {
            [$text, $images] = self::parts($message['content']);

            $message['content'] = $text;

            if ($images !== []) {
                $message['images'] = $images;
            }
        }

        foreach ($message['tool_calls'] ?? [] as $index => $call) {
            $arguments = $call['function']['arguments'] ?? '{}';

            if (is_string($arguments)) {
                $message['tool_calls'][$index]['function']['arguments'] = json_decode($arguments === '' ? '{}' : $arguments, true) ?? new stdClass;
            }
        }

        return $message;
    }

    /** @return array{0: string, 1: list<string>} */
    private static function parts(array $parts): array
    {
        $text = [];
        $images = [];

        foreach ($parts as $part) {
            if (($part['type'] ?? null) === 'text') {
                $text[] = $part['text'] ?? '';
            }

            if (($part['type'] ?? null) === 'image_url' && preg_match('#^data:[^;]+;base64,(.+)$#s', (string) ($part['image_url']['url'] ?? ''), $match) === 1) {
                $images[] = $match[1];
            }
        }

        return [implode("\n", $text), $images];
    }

    private static function format(?array $responseFormat): array|string|null
    {
        return match ($responseFormat['type'] ?? null) {
            'json_schema' => $responseFormat['json_schema']['schema'] ?? 'json',
            'json_object' => 'json',
            default => null,
        };
    }
}
