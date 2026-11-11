<?php

use App\Ai\AiManager;
use App\Ai\Compare\CallReplay;
use App\Ai\Compare\CompareResult;
use App\Ai\Compare\CostModel;
use App\Ai\Compare\GoldenCase;
use App\Ai\Compare\GoldenSet;
use App\Ai\Compare\Scorers;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Usage;
use App\Ai\Prompts\JudgePrompt;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Support\Router;
use App\Ai\Support\Signals;
use App\Facades\Ai;
use App\Models\ModelCall;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    config([
        'ai.providers.local-70b.model' => 'mid-example',
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
        'ai.rates.frontier' => ['*' => ['in' => 3.0, 'out' => 15.0]],
        'ai.pricing.gpu.local' => ['usd_per_hour' => 0.0, 'concurrency' => 1],
        'ai.pricing.gpu.local-70b' => ['usd_per_hour' => 1.0, 'concurrency' => 1],
    ]);
});

function writeGolden(array $cases): string
{
    $path = sys_get_temp_dir().'/golden-'.bin2hex(random_bytes(4)).'.jsonl';

    file_put_contents($path, implode("\n", array_map('json_encode', $cases))."\n");

    return $path;
}

function classifyCase(int $n, string $label): array
{
    return ['id' => "classify-{$n}", 'feature' => 'ticket.classify', 'category' => $label, 'type' => 'exact', 'input' => ['message' => "message {$n}"], 'expected' => ['label' => $label]];
}

function compareOutput(array $arguments): string
{
    Artisan::call('ai:compare', $arguments + ['--no-save' => true]);

    return Artisan::output();
}

describe('the shipped golden set', function () {
    it('has the reply questions from Chapter 15 and the twenty labelled tickets from Chapter 9', function () {
        $cases = GoldenSet::load(base_path('storage/golden/support.jsonl'));

        $byFeature = collect($cases)->groupBy->feature->map->count();

        expect($byFeature['ticket.classify'])->toBe(20)
            ->and($byFeature['support.reply'])->toBe(108)
            ->and(collect($cases)->where('feature', 'support.reply')->every(fn (GoldenCase $case) => $case->type === 'recall' && $case->expected['articles'] !== []))->toBeTrue()
            ->and(collect($cases)->pluck('id')->unique()->count())->toBe(128);
    });

    it('only names articles that exist in the help library', function () {
        $missing = collect(GoldenSet::load(base_path('storage/golden/support.jsonl')))
            ->where('feature', 'support.reply')
            ->flatMap(fn (GoldenCase $case) => $case->expected['articles'])
            ->unique()
            ->reject(fn (string $article) => file_exists(resource_path("help/{$article}.md")))
            ->values()
            ->all();

        expect($missing)->toBe([]);
    });
});

describe('scoring', function () {
    beforeEach(function () {
        $this->scorers = new Scorers(app(AiManager::class), new CitationChecker);
    });

    it('matches a classification label exactly, ignoring case, quotes and a full stop', function (string $reply, float $score) {
        $case = GoldenCase::fromArray(classifyCase(1, 'billing'));

        expect($this->scorers->exact($case, $reply))->toBe($score);
    })->with([['billing', 1.0], ["  Billing.\n", 1.0], ['"billing"', 1.0], ['howto', 0.0], ['It looks like billing', 0.0]]);

    it('scores recall by whether the reply cites a passage from an expected article', function () {
        $case = GoldenCase::fromArray(['id' => 'r1', 'feature' => 'support.reply', 'category' => 'billing', 'type' => 'recall', 'input' => ['question' => 'q'], 'expected' => ['articles' => ['billing/late-fees']]]);

        $late = retrievedChunk(source: 'help/billing/late-fees.md');
        $other = retrievedChunk('Refunds go back to the card.', 'help/billing/refunds.md', 'Refunds › Issuing a refund');

        $labels = ['late-fees › When' => $late, 'refunds › Issuing' => $other];

        expect($this->scorers->recall($case, 'The fee starts after 30 days [late-fees › When].', $labels))->toBe(1.0)
            ->and($this->scorers->recall($case, 'Refunds go to the card [refunds › Issuing].', $labels))->toBe(0.0)
            ->and($this->scorers->recall($case, 'I am not sure.', $labels))->toBe(0.0);
    });

    it('treats declining as the right answer when nothing is expected', function () {
        $case = GoldenCase::fromArray(['id' => 'r2', 'feature' => 'support.reply', 'category' => 'howto', 'type' => 'recall', 'input' => ['question' => 'pizza'], 'expected' => ['articles' => []]]);

        expect($this->scorers->recall($case, 'I can only help with Ledgerly.', []))->toBe(1.0);
    });

    it('asks a judge for a digit, maps 1 to 5 onto 0 to 1, and survives a judge that is down', function () {
        $case = GoldenCase::fromArray(['id' => 'r1', 'feature' => 'support.reply', 'category' => 'billing', 'type' => 'recall', 'input' => ['question' => 'When is a late fee applied?'], 'expected' => ['articles' => ['billing/late-fees'], 'headings' => ['When a late fee is applied']]]);

        Http::fake(['*/chat/completions' => Http::sequence()->push(chatBody('5'))->push(chatBody('Score: 3'))->push(chatBody('no idea'))->push('down', 500)]);

        $judge = new Scorers(app(AiManager::class), new CitationChecker, 'local');

        expect($judge->judged($case, 'After 30 days.'))->toBe(1.0)
            ->and($judge->judged($case, 'Maybe.'))->toBe(0.5)
            ->and($judge->judged($case, 'x'))->toBeNull()
            ->and($judge->judged($case, 'x'))->toBeNull();

        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'When is a late fee applied?') && $request['temperature'] === 0);
    });

    it('does nothing without a judge', function () {
        $case = GoldenCase::fromArray(classifyCase(1, 'billing'));

        expect($this->scorers->judged($case, 'billing'))->toBeNull()
            ->and(md5(JudgePrompt::system()))->toBe(md5(JudgePrompt::system()));
    });
});

describe('cost', function () {
    it('prices a hosted provider per token from the rates table, in dollars', function () {
        expect((new CostModel)->of('frontier', 'frontier-example', new Usage(1000, 100), 3000))->toEqualWithDelta(0.0045, 1e-9);   // 1000 × $3/M + 100 × $15/M
    });

    it('prices your own hardware by the share of the GPU-hour a request used', function () {
        config(['ai.pricing.gpu.local-70b' => ['usd_per_hour' => 1.80, 'concurrency' => 1]]);

        expect((new CostModel)->of('local-70b', 'mid-example', new Usage(1000, 100), 2000))->toEqualWithDelta(0.001, 1e-9);   // $1.80/h × 2 s

        config(['ai.pricing.gpu.local-70b' => ['usd_per_hour' => 1.80, 'concurrency' => 4]]);

        expect((new CostModel)->of('local-70b', 'mid-example', new Usage(1000, 100), 2000))->toEqualWithDelta(0.00025, 1e-9);   // four requests share the GPU
    });

    it('is zero for a laptop you already own', function () {
        expect((new CostModel)->of('local', 'llama3.1:8b', new Usage(1000, 100), 5000))->toBe(0.0);
    });
});

describe('the table', function () {
    it('folds samples into quality, tokens, p50, dollars per 1K requests and quality per dollar', function () {
        $case = fn (string $id, string $category) => GoldenCase::fromArray(['id' => $id, 'feature' => 'support.reply', 'category' => $category, 'type' => 'recall', 'input' => ['question' => 'q'], 'expected' => ['articles' => []]]);

        $sample = fn (string $provider, GoldenCase $case, float $quality, int $ms, float $cost) => ['provider' => $provider, 'case' => $case, 'quality' => $quality, 'in' => 1140, 'out' => 90, 'ms' => $ms, 'ttft' => null, 'cost' => $cost, 'error' => null];

        $result = new CompareResult([
            $sample('mid', $case('a', 'billing'), 0.90, 2000, 0.0014),
            $sample('mid', $case('b', 'howto'), 0.92, 2200, 0.0014),
            $sample('mid', $case('c', 'howto'), 0.91, 2100, 0.0014),
            ['provider' => 'mid', 'case' => $case('d', 'howto'), 'error' => 'RateLimited: slow down'],
        ], ['mid']);

        [$row] = $result->rows();

        expect($row['quality'])->toBe(0.91)
            ->and($row['cases'])->toBe(4)
            ->and($row['errors'])->toBe(1)
            ->and($row['p50_ms'])->toBe(2100)
            ->and($row['usd_per_1k'])->toBe(1.4)
            ->and($row['quality_per_dollar'])->toBe(0.65)                           // Figure 20-8: 0.91 over $1.40
            ->and($result->byCategory()['support.reply']['mid'])->toBe(['billing' => 0.9, 'howto' => 0.92])
            ->and($result->errors()[0])->toBe(['provider' => 'mid', 'case' => 'd', 'error' => 'RateLimited: slow down']);
    });
});

describe('ai:compare', function () {
    it('runs one golden set against several providers and prints the table', function () {
        $golden = writeGolden([classifyCase(1, 'billing'), classifyCase(2, 'howto')]);

        Ai::fake()->for('compare.ticket.classify')
            ->respondWith('billing')->respondWith('billing')->respondWith('billing')     // case 1: local, mid, frontier
            ->respondWith('billing')->respondWith('howto')->respondWith('howto');        // case 2

        $output = compareOutput(['--golden' => $golden, '--providers' => 'local,mid,frontier']);

        expect($output)->toContain('2 cases × 3 providers (local, local-70b, frontier)')
            ->toMatch('/ticket\.classify\s+local\s+0\.50/')
            ->toMatch('/ticket\.classify\s+local-70b\s+1\.00/')
            ->toMatch('/ticket\.classify\s+frontier\s+1\.00/')
            ->toContain('by category (quality), ticket.classify')
            ->toContain('quality per $');

        unlink($golden);
    });

    it('sends every provider the same messages', function () {
        $golden = writeGolden([classifyCase(1, 'billing')]);

        Ai::fake()->for('compare.ticket.classify')->respondWith('billing', times: 3);

        compareOutput(['--golden' => $golden, '--providers' => 'local,mid,frontier']);

        $messages = collect(Ai::fake()->sent())->map(fn (array $call) => json_encode($call['request']->messages))->unique();

        expect($messages)->toHaveCount(1)
            ->and(collect(Ai::fake()->sent())->pluck('provider')->all())->toBe(['local', 'local-70b', 'frontier']);

        unlink($golden);
    });

    it('prices a provider it can and says plainly when a zero means "not priced here"', function () {
        $golden = writeGolden([classifyCase(1, 'billing')]);

        Ai::fake()->for('compare.ticket.classify')->respondWith('billing', times: 3);

        $output = compareOutput(['--golden' => $golden, '--providers' => 'local,frontier']);

        expect($output)->toMatch('/local\s+1\.00\s+\S+\s+\S+\s+\S+\s+—\s+\$0\.00\*\s+∞/')
            ->toContain('* $0.00 means "not priced here"')
            ->toContain('examples, not quotes');

        unlink($golden);
    });

    it('leaves out a provider that is not set up instead of failing the run', function () {
        config(['ai.providers.frontier.api_key' => null, 'ai.providers.local-70b.model' => null]);

        $golden = writeGolden([classifyCase(1, 'billing')]);

        Ai::fake()->for('compare.ticket.classify')->respondWith('billing', times: 3);

        $output = compareOutput(['--golden' => $golden, '--providers' => 'local,mid,frontier']);

        expect($output)->toContain("Skipped mid: the 'local-70b' provider is not set up.")
            ->toContain("Skipped frontier: the 'frontier' provider is not set up.")
            ->toContain('1 cases × 1 providers (local)');

        unlink($golden);
    });

    it('keeps going when one provider fails a request, and reports the failures', function () {
        $golden = writeGolden([classifyCase(1, 'billing'), classifyCase(2, 'billing')]);

        Ai::fake()->for('compare.ticket.classify')
            ->respondWith('billing')->respondWithError(new RuntimeException('overloaded'))->respondWith('billing')->respondWith('billing');

        $output = compareOutput(['--golden' => $golden, '--providers' => 'local,frontier']);

        expect($output)->toMatch('/ticket\.classify\s+frontier\s+1\.00/')
            ->toContain('1 request(s) failed and are left out of the averages. First: frontier on classify-1: RuntimeException: overloaded');

        unlink($golden);
    });

    it('filters by feature and limit, and fails clearly with nothing to run', function () {
        $golden = writeGolden([classifyCase(1, 'billing'), classifyCase(2, 'howto'), classifyCase(3, 'bug')]);

        Ai::fake()->for('compare.ticket.classify')->respondWith('billing', times: 5);

        expect(compareOutput(['--golden' => $golden, '--providers' => 'local', '--limit' => 2]))->toContain('2 cases × 1 providers');

        $exit = Artisan::call('ai:compare', ['--golden' => $golden, '--providers' => 'local', '--feature' => 'support.reply', '--no-save' => true]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('There are no cases to run.');

        unlink($golden);
    });

    it('saves the table as JSON by date', function () {
        $golden = writeGolden([classifyCase(1, 'billing')]);

        Ai::fake()->for('compare.ticket.classify')->respondWith('billing');

        Artisan::call('ai:compare', ['--golden' => $golden, '--providers' => 'local']);

        $path = base_path('storage/benchmarks/compare-'.now()->toDateString().'.json');

        $saved = json_decode(file_get_contents($path), true);

        expect($saved['rows'][0]['provider'])->toBe('local')
            ->and($saved['rows'][0]['quality'])->toEqual(1)
            ->and($saved['cases'])->toBe(1);

        File::delete($path);
        unlink($golden);
    });

    it('feeds the same retrieved passages to every provider for a grounded question, and scores recall', function () {
        config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

        chunkRow(['source' => 'help/billing/late-fees.md', 'heading' => 'Late fees › When a late fee is applied', 'content' => 'A late fee is applied automatically 30 days after the due date.']);

        $golden = writeGolden([['id' => 'reply-1', 'feature' => 'support.reply', 'category' => 'billing', 'type' => 'recall', 'input' => ['question' => 'When is a late fee applied?'], 'expected' => ['articles' => ['billing/late-fees']]]]);

        Http::fake([
            'frontier.example.com/*' => Http::response(chatBody('I am not sure about that.', model: 'frontier-example')),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
            '*/chat/completions' => Http::response(chatBody('A late fee is applied 30 days after the due date [late-fees › When a late fee is applied].')),
        ]);

        $output = compareOutput(['--golden' => $golden, '--providers' => 'local,frontier']);

        expect($output)->toMatch('/support\.reply\s+local\s+1\.00/')
            ->toMatch('/support\.reply\s+frontier\s+0\.00/');

        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), '/chat/completions'))->map(fn (array $pair) => json_encode($pair[0]['messages']))->unique();

        expect($sent)->toHaveCount(1);                                            // the request is built once and sent unchanged

        unlink($golden);
    });

    it('sends providers only shared help text and synthetic questions, never a customer\'s', function () {
        config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

        chunkRow(['tenant_id' => null]);

        $private = chunkRow(['content' => 'Tenant private text: the Birchwood contract number is 7781.']);

        $private->update(['tenant_id' => Tenant::factory()->create()->id]);

        $golden = writeGolden([['id' => 'reply-1', 'feature' => 'support.reply', 'category' => 'billing', 'type' => 'recall', 'input' => ['question' => 'contract number'], 'expected' => ['articles' => []]]]);

        Http::fake([
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
            '*/chat/completions' => Http::response(chatBody('ok')),
        ]);

        compareOutput(['--golden' => $golden, '--providers' => 'local,frontier']);

        Http::assertNotSent(fn (Request $request) => str_contains(json_encode($request->data()), 'Birchwood'));

        unlink($golden);
    });
});

describe('replaying real traffic (--from-calls)', function () {
    afterEach(fn () => config(['ai.debug.record_requests' => false]));

    it('records the normalised request on model_calls only when asked to', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        $send = fn () => Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'Why is INV-4471 late?']], ['max_tokens' => 50], 'support.reply'));

        $send();

        expect(ModelCall::latest('id')->first()->request)->toBeNull();

        config(['ai.debug.record_requests' => true]);

        $send();

        $request = ModelCall::latest('id')->first()->request;

        expect($request['messages'][0]['content'])->toBe('Why is INV-4471 late?')
            ->and($request['options']['max_tokens'])->toBe(50);
    });

    it('replays recorded requests against local providers', function () {
        config(['ai.debug.record_requests' => true]);

        Http::fake(['*/chat/completions' => Http::response(chatBody('recorded answer'))]);

        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'Why is INV-4471 late?']], [], 'support.reply'));

        Ai::fake()->for('compare.support.reply')->respondWith('replayed answer', times: 2);

        $output = compareOutput(['--from-calls' => true, '--providers' => 'local,mid']);

        expect($output)->toContain('1 cases × 2 providers (local, local-70b)')
            ->toMatch('/support\.reply\s+local\s+—/');                            // no expected answer, no judge: tokens and latency only

        expect(collect(Ai::fake()->sent())->first()['request']->messages[0]['content'])->toBe('Why is INV-4471 late?');
    });

    it('refuses to send recorded customer requests to a hosted provider or a hosted judge', function () {
        config(['ai.debug.record_requests' => true]);

        Http::fake(['*/chat/completions' => Http::response(chatBody('x'))]);

        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'Birchwood contract 7781']], [], 'support.reply'));

        Http::fake();

        expect(Artisan::call('ai:compare', ['--from-calls' => true, '--providers' => 'local,frontier', '--no-save' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('frontier is a hosted provider: that would send customer text off your network');

        expect(Artisan::call('ai:compare', ['--from-calls' => true, '--providers' => 'local', '--judge' => 'frontier', '--no-save' => true]))->toBe(1);

        Http::assertNothingSent();
    });

    it('only looks back as far as asked', function () {
        ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'request' => ['messages' => [['role' => 'user', 'content' => 'old']]]]);

        ModelCall::query()->update(['created_at' => now()->subDays(3)]);

        expect(CallReplay::cases(null, CarbonImmutable::now()->subHours(24)))->toBe([])
            ->and(CallReplay::cases(null, CarbonImmutable::now()->subDays(5)))->toHaveCount(1);
    });

    it('clears recorded requests after a week and when the user is forgotten', function () {
        [$user] = customerWithInvoice();

        $conversation = conversationFor($user);

        $old = ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'request' => ['messages' => []], 'conversation_id' => $conversation->id]);
        $recent = ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'request' => ['messages' => []]]);

        ModelCall::query()->whereKey($old->id)->update(['created_at' => now()->subDays(8)]);

        $this->artisan('conversations:retain')->assertSuccessful();

        expect($old->fresh()->request)->toBeNull()
            ->and($recent->fresh()->request)->not->toBeNull();

        ModelCall::query()->whereKey($recent->id)->update(['conversation_id' => $conversation->id]);

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])->assertSuccessful();

        expect($recent->fresh()->request)->toBeNull();
    });
});

describe('model tiers (Figure 20-5)', function () {
    it('keeps easy requests small, sends uncertain or consequential ones to mid, and the hard few to frontier', function (Signals $signals, string $tier) {
        expect(app(Router::class)->tierFor($signals))->toBe($tier);
    })->with([
        'a clear how-to' => [new Signals, 'small'],
        'a split vote' => [new Signals(agreement: 0.5), 'mid'],
        'two asks in one message' => [new Signals(multiAsk: true), 'mid'],
        'a dispute' => [new Signals(isDispute: true), 'mid'],
        'two unresolved agentic rounds' => [new Signals(unresolvedRounds: 2), 'frontier'],
        'a person flagged it' => [new Signals(humanFlagged: true), 'frontier'],
        'flagged and a dispute: the higher tier wins' => [new Signals(isDispute: true, humanFlagged: true), 'frontier'],
    ]);

    it('names the provider behind the tier, and stays on the best one below when a tier is not set up', function () {
        $router = app(Router::class);

        expect($router->providerFor(new Signals(humanFlagged: true)))->toBe('frontier')
            ->and($router->providerFor(new Signals(isDispute: true)))->toBe('local-70b')
            ->and($router->providerFor(new Signals))->toBe('local');

        config(['ai.providers.frontier.api_key' => null]);

        expect($router->providerFor(new Signals(humanFlagged: true)))->toBe('local-70b');

        config(['ai.providers.local-70b.model' => null]);

        expect($router->providerFor(new Signals(humanFlagged: true)))->toBe('local')
            ->and($router->providerFor(new Signals(isDispute: true)))->toBe('local');
    });
});
