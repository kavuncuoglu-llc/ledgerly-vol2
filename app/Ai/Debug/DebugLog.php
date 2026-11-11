<?php

namespace App\Ai\Debug;

use App\Ai\Contracts\ChatRequest;

// Chapter 19, Topic 7: the trapdoor under the abstraction. For the features and conversations listed in config('ai.debug'), the exact request and response bodies are appended to
// storage/logs/ai-debug-*.log. The API key travels in a header and is never in a body. The bodies hold customer text, so this is off until you name what to watch.
final class DebugLog
{
    public static function wants(ChatRequest $request): bool
    {
        if (in_array($request->feature, (array) config('ai.debug.features', []), true)) {
            return true;
        }

        $conversation = $request->meta['conversation_id'] ?? null;

        return $conversation !== null && in_array($conversation, (array) config('ai.debug.conversations', []), true);
    }

    public static function write(ChatRequest $request, string $provider, array $requestBody, mixed $responseBody, int $status): void
    {
        $name = $request->meta['conversation_id'] ?? null;

        $file = storage_path('logs/ai-debug-'.($name !== null && in_array($name, (array) config('ai.debug.conversations', []), true) ? $name : preg_replace('/[^a-z0-9._-]/i', '_', $request->feature)).'.log');

        file_put_contents($file, json_encode([
            'at' => now()->toIso8601String(),
            'provider' => $provider,
            'feature' => $request->feature,
            'status' => $status,
            'request' => $requestBody,
            'response' => $responseBody,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }
}
