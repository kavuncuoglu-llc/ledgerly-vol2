<?php

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Events\ModelCalled;
use App\Ai\Tokens\RatioCounter;
use App\Facades\Ai;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    Cache::flush();
});

describe('PrefixCacheEstimator', function () {
    beforeEach(function () {
        $this->estimator = new PrefixCacheEstimator(new RatioCounter);

        $this->system = ['role' => 'system', 'content' => str_repeat('s', 720)];
    });

    it('finds the length of the shared prefix', function () {
        expect($this->estimator->sharedPrefixLength('abcdef', 'abcxyz'))->toBe(3)
            ->and($this->estimator->sharedPrefixLength('abc', 'abcdef'))->toBe(3)
            ->and($this->estimator->sharedPrefixLength('', 'abc'))->toBe(0)
            ->and($this->estimator->sharedPrefixLength('abc', 'abc'))->toBe(3)
            ->and($this->estimator->sharedPrefixLength('xbc', 'abc'))->toBe(0);
    });

    it('estimates nothing on the first call of a conversation', function () {
        expect($this->estimator->estimate(7, 'support.reply', [], [$this->system]))->toBe(0);
    });

    it('estimates the prefix a call shares with the previous one in the same conversation', function () {
        $this->estimator->estimate(7, 'support.reply', [], [$this->system, ['role' => 'user', 'content' => 'First question']]);

        $cached = $this->estimator->estimate(7, 'support.reply', [], [$this->system, ['role' => 'user', 'content' => 'Second question']]);

        expect($cached)->toBeGreaterThan(190)->toBeLessThan(230);               // the 720-character system message is about 200 tokens
    });

    it('counts the whole previous prompt when this one only appends to it', function () {
        $first = [$this->system, ['role' => 'user', 'content' => 'First question']];

        $this->estimator->estimate(7, 'support.reply', [], $first);

        $cached = $this->estimator->estimate(7, 'support.reply', [], [...$first, ['role' => 'assistant', 'content' => 'First answer'], ['role' => 'user', 'content' => 'Second']]);

        expect($cached)->toBeGreaterThan((new RatioCounter)->count(json_encode([[], $first])) - 10);
    });

    it('keeps conversations and features apart', function () {
        $this->estimator->estimate(7, 'support.reply', [], [$this->system]);

        expect($this->estimator->estimate(8, 'support.reply', [], [$this->system]))->toBe(0)
            ->and($this->estimator->estimate(7, 'conversation.summarize', [], [$this->system]))->toBe(0);
    });

    it('treats a change in the tool definitions as a change in the prefix', function () {
        $this->estimator->estimate(7, 'support.reply', [['name' => 'a']], [$this->system]);

        expect($this->estimator->estimate(7, 'support.reply', [['name' => 'b']], [$this->system]))->toBeLessThan(5);
    });

    it('forgets a conversation after an hour', function () {
        $this->estimator->estimate(7, 'support.reply', [], [$this->system]);

        $this->travel(61)->minutes();

        expect($this->estimator->estimate(7, 'support.reply', [], [$this->system]))->toBe(0);
    });
});

describe('what the usage-logging decorator puts on ModelCalled', function () {
    function chatThroughLayer(array $messages, array $options, string $feature): void
    {
        Ai::for($feature)->chat(ChatRequest::make($messages, $options, $feature));
    }

    function recordedFields(): ModelCall
    {
        return ModelCall::latest('id')->first();
    }

    it('records the cached tokens the provider reports', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok', usage: ['prompt_tokens' => 2000, 'completion_tokens' => 10, 'prompt_tokens_details' => ['cached_tokens' => 1536]]))]);

        chatThroughLayer([['role' => 'user', 'content' => 'hi']], ['conversation_id' => 7], 'support.reply');

        expect(recordedFields()->cached_tokens)->toBe(1536);
    });

    it('estimates them from the shared prefix when the server does not say', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok', usage: ['prompt_tokens' => 4000, 'completion_tokens' => 10]))]);

        $system = ['role' => 'system', 'content' => str_repeat('s', 720)];

        chatThroughLayer([$system, ['role' => 'user', 'content' => 'one']], ['conversation_id' => 7], 'support.reply');

        expect(recordedFields()->cached_tokens)->toBe(0);

        chatThroughLayer([$system, ['role' => 'user', 'content' => 'two']], ['conversation_id' => 7], 'support.reply');

        expect(recordedFields()->cached_tokens)->toBeGreaterThan(190);
    });

    it('never estimates more cached tokens than the server says the prompt had', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok', usage: ['prompt_tokens' => 100, 'completion_tokens' => 10]))]);

        $system = ['role' => 'system', 'content' => str_repeat('s', 720)];

        chatThroughLayer([$system, ['role' => 'user', 'content' => 'one']], ['conversation_id' => 7], 'support.reply');
        chatThroughLayer([$system, ['role' => 'user', 'content' => 'two']], ['conversation_id' => 7], 'support.reply');

        expect(recordedFields()->cached_tokens)->toBe(100);
    });

    it('records nothing about caching for a call outside a conversation', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        chatThroughLayer([['role' => 'user', 'content' => 'hi']], [], 'cli.chat');

        expect(recordedFields()->cached_tokens)->toBeNull();
    });

    it('records the context breakdown and the semantic cache flag, and keeps both out of the request', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        chatThroughLayer([['role' => 'user', 'content' => 'hi']], ['context_breakdown' => ['system' => 440, 'history' => 1620], 'semantic_cache_hit' => false], 'support.reply');

        expect(recordedFields()->context_breakdown)->toBe(['system' => 440, 'history' => 1620])
            ->and(recordedFields()->semantic_cache_hit)->toBeFalse();

        Http::assertSent(fn (Request $request) => ! array_key_exists('context_breakdown', $request->data()) && ! array_key_exists('semantic_cache_hit', $request->data()));
    });

    it('records the time to the first delta when streaming', function () {
        $sse = 'data: '.json_encode(['id' => 'x', 'model' => 'llama3.1:8b', 'choices' => [['delta' => ['content' => 'Hel']]]])."\n\n"
            .'data: '.json_encode(['choices' => [['delta' => ['content' => 'lo'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 2]])."\n\n"
            ."data: [DONE]\n\n";

        Http::fake(['*/chat/completions' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $stream = Ai::for('cli.stream')->stream(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'cli.stream'));

        foreach ($stream as $delta) {
            //
        }

        expect(recordedFields()->ttft_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and(recordedFields()->ttft_ms)->toBeLessThanOrEqual(recordedFields()->latency_ms);
    });

    it('leaves the time to first token empty for a call that does not stream', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        chatThroughLayer([['role' => 'user', 'content' => 'hi']], [], 'support.reply');

        expect(recordedFields()->ttft_ms)->toBeNull();
    });

    it('takes the four new fields at the end of ModelCalled, after repaired, so earlier callers still work', function () {
        $event = new ModelCalled('f', 'p', 'm', [], 'stop', 1, 7, 'v1', true, 100, 50, ['system' => 1], true);

        expect([$event->repaired, $event->cachedTokens, $event->ttftMs, $event->contextBreakdown, $event->semanticCacheHit])->toBe([true, 100, 50, ['system' => 1], true]);

        $minimal = new ModelCalled('f', 'p', 'm', [], 'stop', 1);

        expect([$minimal->cachedTokens, $minimal->ttftMs, $minimal->contextBreakdown, $minimal->semanticCacheHit])->toBe([null, null, null, null]);
    });

    it('lets a usage block carry the cached tokens when the event does not', function () {
        event(new ModelCalled('support.reply', 'openai', 'gpt-x', ['prompt_tokens' => 100, 'completion_tokens' => 5, 'cached_tokens' => 64], 'stop', 10));

        expect(recordedFields()->cached_tokens)->toBe(64);
    });
});

describe('two consecutive turns of the assistant', function () {
    it('share a prefix that covers the system message and the history, and the estimate lands in model_calls', function () {
        [$user] = customerWithInvoice();

        $big = ['prompt_tokens' => 4000, 'completion_tokens' => 10];

        fakeChatSequence(chatBody('One.', usage: $big), chatBody('Two.', usage: $big), chatBody('Three.', usage: $big));

        $id = askAssistant($user, ['question' => 'Why is my March invoice late?'])->json('conversation_id');
        askAssistant($user, ['question' => 'What about the April one?', 'conversation_id' => $id]);
        askAssistant($user, ['question' => 'And May?', 'conversation_id' => $id]);

        $calls = ModelCall::where('feature', 'support.reply')->orderBy('id')->get();

        expect($calls->pluck('cached_tokens')->all()[0])->toBe(0)
            ->and($calls[1]->cached_tokens)->toBeGreaterThan(250)                  // the system message (about 310 tokens) at least
            ->and($calls[2]->cached_tokens)->toBeGreaterThan($calls[1]->cached_tokens);   // and the first exchange as well
    });

    it('send the tool definitions in the same order every time', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(chatBody('One.'), chatBody('Two.'));

        askAssistant($user, ['question' => 'Hello']);
        askAssistant(User::factory()->owner()->create(), ['question' => 'Hello again']);

        [$first, $second] = sentChatBodies();

        expect(collect($first['tools'])->pluck('function.name')->all())->toBe(collect($second['tools'])->pluck('function.name')->all())
            ->and(collect($first['tools'])->pluck('function.name')->all())->toBe(collect($first['tools'])->pluck('function.name')->sort()->values()->all());
    });
});
