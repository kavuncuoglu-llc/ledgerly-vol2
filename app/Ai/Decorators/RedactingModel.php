<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Memory\MemoryBlock;
use App\Ai\Privacy\NerUnavailable;
use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Privacy\StreamRestorer;
use App\Ai\Privacy\Vault;
use App\Ai\Privacy\VaultFactory;
use App\Facades\Ai;
use Generator;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;

// Chapter 34, Topic 9: inside TracingModel and outside UsageLoggingModel, so the audit trail stores the redacted prompt and the restored answer, and nothing between the app and the provider sees an original value.
// Detect, tokenize through the conversation's vault, call, restore. Tool arguments in the response are not restored here: the gate does that, for the tools that say they accept it.
// Fail closed: when names and addresses cannot be screened, a hosted call goes to the local role instead of going out half-screened.
final class RedactingModel extends ModelDecorator
{
    public function __construct(
        ChatModel $inner,
        private PersonalDataDetector $detector,
        private VaultFactory $vaults,
        private array $providerConfig = [],
    ) {
        parent::__construct($inner);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        if (! $this->redacts()) {
            return $this->inner->chat($request);                                  // local by default: the data never leaves the machine
        }

        $prepared = $this->prepare($request);

        if ($prepared === null) {
            return $this->reroute($request)->chat($request);
        }

        [$redacted, $vault] = $prepared;

        return $this->restored($this->inner->chat($redacted), $vault);
    }

    public function stream(ChatRequest $request): Generator
    {
        if (! $this->redacts()) {
            return yield from $this->inner->stream($request);
        }

        $prepared = $this->prepare($request);

        if ($prepared === null) {
            return yield from $this->reroute($request)->stream($request);
        }

        [$redacted, $vault] = $prepared;

        $restorer = new StreamRestorer($vault);

        $stream = $this->inner->stream($redacted);

        foreach ($stream as $event) {
            if (! $event instanceof Delta) {
                yield $event;

                continue;
            }

            $text = $restorer->feed($event->text);

            if ($text !== '') {
                yield new Delta($text);
            }
        }

        $rest = $restorer->flush();

        if ($rest !== '') {
            yield new Delta($rest);
        }

        return $this->restored($stream->getReturn(), $vault);
    }

    private function redacts(): bool
    {
        return (bool) ($this->providerConfig['privacy']['redact'] ?? true);
    }

    // The local role is where a call goes when names cannot be screened; it is never rerouted away from itself.
    private function reroute(ChatRequest $request): ChatModel
    {
        Span::getCurrent()->setAttribute('ledgerly.redaction.rerouted', 'local');

        Log::warning('redaction.rerouted', ['provider' => $this->inner->name(), 'feature' => $request->feature, 'to' => 'local']);

        return Ai::chat('local');
    }

    private function canReroute(): bool
    {
        return $this->inner->name() !== 'local';
    }

    /** @return array{0: ChatRequest, 1: Vault}|null null when the call has to go somewhere else */
    private function prepare(ChatRequest $request): ?array
    {
        $withNames = $this->detector->nerAvailable();

        if (! $withNames && $this->canReroute()) {
            return null;
        }

        $vault = $this->vaults->for($request->meta['conversation_id'] ?? null);

        try {
            $messages = array_map(fn (array $message) => $this->redactMessage($message, $vault, $withNames), $request->messages);
        } catch (NerUnavailable) {
            if ($this->canReroute()) {
                return null;
            }

            $messages = array_map(fn (array $message) => $this->redactMessage($message, $vault, false), $request->messages);
        }

        return [$request->with(messages: $messages), $vault];
    }

    private function redactMessage(array $message, Vault $vault, bool $withNames): array
    {
        if (($message['role'] ?? null) === 'system') {
            return $this->redactMemoryBlock($message, $vault, $withNames);
        }

        $message['content'] = $this->redactContent($message['content'] ?? null, $vault, $withNames);

        foreach ($message['tool_calls'] ?? [] as $index => $call) {
            if (is_string($call['function']['arguments'] ?? null)) {
                $message['tool_calls'][$index]['function']['arguments'] = $this->redactText($call['function']['arguments'], $vault, $withNames);
            }
        }

        return $message;
    }

    // Memories ride inside the system message (Chapter 13): what the customer told the assistant about themselves is personal data, the instructions around it are not.
    private function redactMemoryBlock(array $message, Vault $vault, bool $withNames): array
    {
        $content = $message['content'] ?? null;

        if (! is_string($content)) {
            return $message;
        }

        $at = strpos($content, MemoryBlock::HEADER);

        if ($at === false) {
            return $message;
        }

        $message['content'] = substr($content, 0, $at).$this->redactText(substr($content, $at), $vault, $withNames);

        return $message;
    }

    private function redactContent(mixed $content, Vault $vault, bool $withNames): mixed
    {
        if (is_string($content)) {
            return $this->redactText($content, $vault, $withNames);
        }

        if (! is_array($content)) {
            return $content;
        }

        return array_map(function ($part) use ($vault, $withNames) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $part['text'] = $this->redactText($part['text'], $vault, $withNames);
            }

            return $part;
        }, $content);
    }

    private function redactText(string $text, Vault $vault, bool $withNames): string
    {
        $spans = $withNames
            ? $this->detector->find($text)
            : $this->detector->findPatterns($text);

        return $vault->tokenize($text, $spans);
    }

    private function restored(ChatResponse $response, Vault $vault): ChatResponse
    {
        if ($response->content === null) {
            return $response;
        }

        return $response->withContent($vault->restore($response->content));
    }
}
