<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;
use App\Ai\Testing\FakeAi;
use App\Ai\Testing\ScriptedJsonInvalid;
use Generator;
use RuntimeException;
use Throwable;

// Implements the interfaces completely, so every feature runs against it unchanged. It returns what was scripted, remembers what it was sent, and checks scripted JSON against the request's response_format.
final class FakeDriver implements ChatModel, EmbeddingModel
{
    public function __construct(private string $name, private FakeAi $fake, private int $dimensions = 768) {}

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return 'fake';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function supports(Capability $capability): bool
    {
        return ! in_array($capability, [Capability::Grammar, Capability::Tokenize], true);   // "everything", as ai:providers prints it, except what only llama-server's engine endpoints give
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $stream = $this->stream($request);

        foreach ($stream as $ignored) {
            // drain it: chat() is a stream read to the end
        }

        return $stream->getReturn();
    }

    public function stream(ChatRequest $request): Generator
    {
        $this->fake->record($this->name, $request);

        $script = $this->fake->next($request->feature);

        if (isset($script['error'])) {
            throw $script['error'] instanceof Throwable ? $script['error'] : new RuntimeException('Scripted failure');
        }

        $content = implode('', $script['deltas']);

        $this->checkAgainstFormat($request, $content, $script);

        foreach ($script['deltas'] as $delta) {
            yield new Delta($delta);
        }

        $toolCalls = [];

        foreach ($script['tool_calls'] ?? [] as $index => $call) {
            $arguments = json_encode($call['arguments'], JSON_UNESCAPED_SLASHES);

            yield new ToolCallStarted($call['name'], $index);
            yield new ToolCallArguments($index, $arguments);

            $toolCalls[] = ['id' => $call['id'], 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => $arguments]];
        }

        return new ChatResponse(
            id: 'fake-'.bin2hex(random_bytes(3)),
            model: $request->model ?? 'fake',
            content: $content === '' && $toolCalls !== [] ? null : $content,
            toolCalls: $toolCalls,
            finishReason: $script['finish'],
            usage: new Usage((int) ceil(strlen(json_encode($request->messages)) / 4), (int) ceil(strlen($content) / 4)),
            raw: [],
        );
    }

    public function embed(array $texts, EmbedKind $kind, ?string $model = null): array
    {
        return array_map(function (string $text) {
            mt_srand(crc32($text));                                                // the same text always gets the same vector

            $vector = array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, $this->dimensions));
            $length = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector))) ?: 1.0;

            return array_map(fn (float $v) => round($v / $length, 6), $vector);
        }, array_values($texts));
    }

    private function checkAgainstFormat(ChatRequest $request, string $content, array $script): void
    {
        $format = $request->responseFormat;

        if ($format === null || ($script['tool_calls'] ?? []) !== []) {
            return;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ScriptedJsonInvalid("The '{$request->feature}' request asked for JSON, but the scripted reply is not a JSON object: {$content}");
        }

        $schema = $format['json_schema']['schema'] ?? null;

        if ($schema === null) {
            return;
        }

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $decoded)) {
                throw new ScriptedJsonInvalid("The scripted reply for '{$request->feature}' is missing the required field '{$key}'.");
            }
        }

        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (array_key_exists($key, $decoded) && ! $this->matchesType($decoded[$key], (array) ($property['type'] ?? []))) {
                throw new ScriptedJsonInvalid("The scripted field '{$key}' for '{$request->feature}' is not of type ".implode('|', (array) $property['type']).'.');
            }
        }
    }

    private function matchesType(mixed $value, array $types): bool
    {
        if ($types === []) {
            return true;
        }

        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value),
                'null' => $value === null,
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
