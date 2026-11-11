<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

// Chapter 19, Topic 6: a scripted model plus a request recorder, the Http::fake() / Mail::fake() idiom applied to models. Everything above the seam can be tested with it and no server.
final class FakeAi
{
    /** @var array<string, list<array>> */
    private array $scripts = [];

    /** @var list<array{provider: string, request: ChatRequest}> */
    private array $sent = [];

    private ?string $current = null;

    private ?string $fallback = null;

    public function for(string $feature): self
    {
        $this->current = $feature;

        return $this;
    }

    public function respondWith(string $content, int $times = 1, FinishReason $finishReason = FinishReason::Stop): self
    {
        return $this->push(['deltas' => [$content], 'finish' => $finishReason], $times);
    }

    public function respondWithJson(array $data, int $times = 1): self
    {
        return $this->push(['deltas' => [json_encode($data, JSON_UNESCAPED_SLASHES)], 'finish' => FinishReason::Stop], $times);
    }

    public function respondWithToolCall(string $name, array $arguments, int $times = 1, ?string $id = null): self
    {
        return $this->push(['deltas' => [], 'tool_calls' => [['id' => $id ?? 'call_'.bin2hex(random_bytes(3)), 'name' => $name, 'arguments' => $arguments]], 'finish' => FinishReason::ToolCalls], $times);
    }

    /** @param string[] $deltas */
    public function respondWithStream(array $deltas, int $times = 1): self
    {
        return $this->push(['deltas' => $deltas, 'finish' => FinishReason::Stop], $times);
    }

    public function respondWithError(Throwable $error, int $times = 1): self
    {
        return $this->push(['error' => $error], $times);
    }

    public function respondToEverythingWith(string $content): self
    {
        $this->fallback = $content;

        return $this;
    }

    public function next(string $feature): array
    {
        $script = isset($this->scripts[$feature])
            ? array_shift($this->scripts[$feature])
            : null;

        if ($script !== null) {
            return $script;
        }

        if ($this->fallback !== null) {
            return ['deltas' => [$this->fallback], 'finish' => FinishReason::Stop];
        }

        throw new UnscriptedCall("Ai::fake() has no scripted response left for the '{$feature}' feature. Script it with ->for('{$feature}')->respondWith(...).");
    }

    public function record(string $provider, ChatRequest $request): void
    {
        $this->sent[] = ['provider' => $provider, 'request' => $request];
    }

    /** @return list<array{provider: string, request: ChatRequest}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function assertSent(Closure $matches): void
    {
        Assert::assertTrue(
            collect($this->sent)->contains(fn (array $call) => $matches($call['request'], $call['provider'])),
            'No request matching the callback was sent. Sent: '.$this->describe(),
        );
    }

    public function assertSentCount(string $feature, int $times): void
    {
        $count = collect($this->sent)->filter(fn (array $call) => $call['request']->feature === $feature)->count();

        Assert::assertSame($times, $count, "Expected {$times} requests for '{$feature}', {$count} were sent. Sent: ".$this->describe());
    }

    public function assertNothingSentTo(string $provider): void
    {
        $count = collect($this->sent)->where('provider', $provider)->count();

        Assert::assertSame(0, $count, "Expected nothing to be sent to '{$provider}', but {$count} request(s) were.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Expected no model requests, but sent: '.$this->describe());
    }

    private function push(array $script, int $times): self
    {
        $feature = $this->current ?? throw new UnscriptedCall('Call ->for(\'feature.name\') before scripting a response.');

        for ($i = 0; $i < $times; $i++) {
            $this->scripts[$feature][] = $script;
        }

        return $this;
    }

    private function describe(): string
    {
        return $this->sent === []
            ? 'nothing'
            : collect($this->sent)->map(fn (array $call) => $call['provider'].':'.$call['request']->feature)->implode(', ');
    }
}
