<?php

namespace App\Ai\Drivers\Prism;

use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage as PrismUsage;

// Chapter 47, Topic 8: the app's normalised request and response on one side, Prism's value objects on the other. This is the code the package costs: everything above it (prompts, memory, retrieval) and everything below it (the HTTP) is somebody else's.
final class PrismTranslator
{
    /** @return array{0: list<SystemMessage>, 1: list<UserMessage|AssistantMessage|ToolResultMessage>} */
    public static function messages(array $messages): array
    {
        $system = [];
        $conversation = [];
        $calls = [];
        $pendingResults = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role !== 'tool' && $pendingResults !== []) {
                $conversation[] = new ToolResultMessage($pendingResults);

                $pendingResults = [];
            }

            if ($role === 'system') {
                $system[] = new SystemMessage(self::text($message['content'] ?? ''));

                continue;
            }

            if ($role === 'tool') {
                $call = $calls[$message['tool_call_id'] ?? ''] ?? ['name' => '', 'arguments' => []];

                $pendingResults[] = new ToolResult(
                    toolCallId: (string) ($message['tool_call_id'] ?? ''),
                    toolName: $call['name'],
                    args: $call['arguments'],
                    result: self::text($message['content'] ?? ''),
                );

                continue;
            }

            if ($role === 'assistant') {
                $toolCalls = [];

                foreach ($message['tool_calls'] ?? [] as $call) {
                    $arguments = self::arguments($call['function']['arguments'] ?? '{}');

                    $calls[$call['id'] ?? ''] = ['name' => $call['function']['name'] ?? '', 'arguments' => $arguments];

                    $toolCalls[] = new ToolCall((string) ($call['id'] ?? ''), (string) ($call['function']['name'] ?? ''), $arguments);
                }

                $conversation[] = new AssistantMessage(self::text($message['content'] ?? ''), $toolCalls);

                continue;
            }

            $conversation[] = new UserMessage(self::text($message['content'] ?? ''));
        }

        if ($pendingResults !== []) {
            $conversation[] = new ToolResultMessage($pendingResults);
        }

        return [$system, $conversation];
    }

    // The tools are described to Prism and never run by it: the handler is a no-op, and the driver runs one step, so the calls come back to the app's tool loop, which dispatches each through ToolGate (Chapter 33).
    /** @return list<Tool> */
    public static function tools(array $tools): array
    {
        return array_map(function (array $definition) {
            $function = $definition['function'] ?? $definition;

            $tool = (new Tool)
                ->as((string) $function['name'])
                ->for((string) ($function['description'] ?? ''))
                ->using(fn (): string => '');

            $required = $function['parameters']['required'] ?? [];

            foreach ($function['parameters']['properties'] ?? [] as $name => $schema) {
                $tool->withParameter(new RawSchema((string) $name, $schema), in_array($name, $required, true));
            }

            return $tool;
        }, $tools);
    }

    public static function schema(array $responseFormat): RawSchema
    {
        if (($responseFormat['type'] ?? null) === 'json_schema') {
            return new RawSchema((string) ($responseFormat['json_schema']['name'] ?? 'reply'), $responseFormat['json_schema']['schema'] ?? ['type' => 'object']);
        }

        return new RawSchema('reply', ['type' => 'object']);
    }

    /** @param iterable<ToolCall> $calls */
    public static function toolCalls(iterable $calls): array
    {
        $shaped = [];

        foreach ($calls as $index => $call) {
            $arguments = $call->arguments();

            $shaped[] = [
                'id' => $call->id !== ''
                    ? $call->id
                    : 'call_'.substr(md5($call->name.json_encode($arguments).$index), 0, 12),
                'type' => 'function',
                'function' => [
                    'name' => $call->name,
                    'arguments' => json_encode($arguments === [] ? new \stdClass : $arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        return $shaped;
    }

    public static function usage(?PrismUsage $usage): Usage
    {
        if ($usage === null) {
            return new Usage(0, 0);
        }

        return new Usage(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            cachedTokens: $usage->cacheReadInputTokens,
            reasoningTokens: $usage->thoughtTokens,
            cacheWriteTokens: $usage->cacheWriteInputTokens,
        );
    }

    public static function finishReason(PrismFinishReason $reason, bool $hasToolCalls): FinishReason
    {
        if ($hasToolCalls) {
            return FinishReason::ToolCalls;
        }

        return match ($reason) {
            PrismFinishReason::Stop => FinishReason::Stop,
            PrismFinishReason::Length => FinishReason::Length,
            PrismFinishReason::ToolCalls => FinishReason::ToolCalls,
            PrismFinishReason::ContentFilter, PrismFinishReason::Refusal => FinishReason::ContentFilter,
            default => FinishReason::Truncated,
        };
    }

    private static function arguments(string|array $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return json_decode($arguments === '' ? '{}' : $arguments, true) ?? [];
    }

    private static function text(string|array|null $content): string
    {
        if (! is_array($content)) {
            return (string) $content;
        }

        return implode("\n", array_map(fn (array $part) => (string) ($part['text'] ?? ''), array_filter($content, fn (array $part) => ($part['type'] ?? 'text') === 'text')));
    }
}
