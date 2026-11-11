<?php

use App\Ai\Cache\CachePolicy;
use App\Models\CachedAnswer;
use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false]);

    $this->seed();

    $this->howto = json_decode(file_get_contents(base_path('storage/samples/howto.json')), true);
});

function howtoWorld(array $howto, int $answers): void
{
    $vectors = [];

    $axis = function (array $weights): array {
        $vector = array_fill(0, 768, 0.0);

        foreach ($weights as $dimension => $weight) {
            $vector[$dimension] = $weight;
        }

        return $vector;
    };

    foreach ($howto['questions'] as $i => $question) {
        $vectors[$question] = $axis([$i => 1.0]);                                      // twenty questions, twenty directions: none is near another
    }

    foreach ($howto['paraphrases'] as $i => $question) {
        $vectors[$question] = $axis([$i => 0.9511, $i + 30 => 0.3090]);                // 18 degrees from its twin: similarity 0.951, above 0.92
    }

    foreach ($howto['different'] as $i => $question) {
        $vectors[$question] = $axis([$i + 60 => 1.0]);
    }

    $sequence = Http::sequence();

    foreach (range(1, $answers) as $i) {
        $sequence->push(chatBody("Answer number {$i}."));
        $sequence->push(classifierReply('howto'));
    }

    Http::fake([
        '*/embeddings' => fn ($request) => Http::response(['data' => [['embedding' => $vectors[preg_replace('/^search_query: /', '', $request['input'])] ?? $axis([700 => 1.0])]]]),
        '*/chat/completions' => $sequence,
    ]);
}

it('ships twenty how-to questions, twenty rephrasings and ten different ones, all able to stand alone', function () {
    $policy = app(CachePolicy::class);

    expect($this->howto['questions'])->toHaveCount(20)
        ->and($this->howto['paraphrases'])->toHaveCount(20)
        ->and($this->howto['different'])->toHaveCount(10)
        ->and(collect($this->howto)->only(['questions', 'paraphrases', 'different'])->flatten()->every(fn (string $q) => $policy->isGeneric($q)))->toBeTrue();
});

describe('support:ask-batch', function () {
    it('answers the twenty questions the first time and from the cache the second time', function () {
        howtoWorld($this->howto, 20);

        $this->artisan('support:ask-batch')->expectsOutputToContain('20 answered · 0 cache hits')->assertSuccessful();

        expect(CachedAnswer::count())->toBe(20);

        $chatCalls = count(sentChatBodies());

        $this->artisan('support:ask-batch')->expectsOutputToContain('20 answered · 20 cache hits')->assertSuccessful();

        expect(count(sentChatBodies()))->toBe($chatCalls)                  // not one more model call
            ->and(ModelCall::where('semantic_cache_hit', true)->count())->toBe(20)
            ->and(CachedAnswer::sum('hit_count'))->toBe(20);
    });

    it('answers the rephrased questions from the same cache', function () {
        howtoWorld($this->howto, 20);

        $this->artisan('support:ask-batch')->assertSuccessful();

        $this->artisan('support:ask-batch', ['--list' => 'paraphrases'])->expectsOutputToContain('20 answered · 20 cache hits')->assertSuccessful();
    });

    it('misses the different questions (Exercise 3)', function () {
        howtoWorld($this->howto, 30);

        $this->artisan('support:ask-batch')->assertSuccessful();

        $this->artisan('support:ask-batch', ['--list' => 'different'])->expectsOutputToContain('10 answered · 0 cache hits')->assertSuccessful();
    });

    it('reports a missing file, list and user', function () {
        $this->artisan('support:ask-batch', ['path' => 'storage/samples/nope.json'])->expectsOutputToContain('No file at storage/samples/nope.json.')->assertFailed();
        $this->artisan('support:ask-batch', ['--list' => 'nothing'])->expectsOutputToContain('The file has no "nothing" list.')->assertFailed();
        $this->artisan('support:ask-batch', ['--user' => 99999])->expectsOutputToContain('There is no user 99999.')->assertFailed();
    });

    it('says so when the model server is down', function () {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->artisan('support:ask-batch')->expectsOutputToContain('Could not reach the model server')->assertFailed();
    });
});

describe('support:ask', function () {
    it('prints the cache line, the similarity and the cached question, then the answer', function () {
        howtoWorld($this->howto, 1);

        $this->artisan('support:ask', ['question' => $this->howto['questions'][0]])->expectsOutputToContain('Answer number 1.')->assertSuccessful();

        $this->artisan('support:ask', ['question' => $this->howto['paraphrases'][0]])
            ->expectsOutputToContain('[semantic cache hit · 0.95 · cached question: "How do I set up recurring invoices?"]')
            ->expectsOutputToContain('Answer number 1.')
            ->assertSuccessful();
    });

    it('prints just the answer on a miss', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('It is 18 days overdue.'))]);

        $this->artisan('support:ask', ['question' => 'Why is my March invoice late?'])->expectsOutput('It is 18 days overdue.')->assertSuccessful();
    });

    it('says so for an unknown user and a model server that is down', function () {
        $this->artisan('support:ask', ['question' => 'Hi', '--user' => 99999])->expectsOutputToContain('There is no user 99999.')->assertFailed();

        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->artisan('support:ask', ['question' => 'Why is my March invoice late?'])->expectsOutputToContain('Could not reach the model server')->assertFailed();
    });
});

describe('cached-answers:review', function () {
    beforeEach(function () {
        foreach ([['Question A?', 5], ['Question B?', 9], ['Question C?', 0]] as [$question, $hits]) {
            CachedAnswer::create(['category' => 'howto', 'question' => $question, 'answer' => "Answer to {$question}", 'help_version' => 'v1', 'hit_count' => $hits, 'embedding' => angledVector(0)]);
        }
    });

    it('lists the unreviewed answers, busiest first', function () {
        Artisan::call('cached-answers:review');

        $output = Artisan::output();

        expect(strpos($output, 'Question B?'))->toBeLessThan(strpos($output, 'Question A?'))
            ->and(strpos($output, 'Question A?'))->toBeLessThan(strpos($output, 'Question C?'))
            ->and($output)->toContain('9 hits')
            ->and($output)->toContain('Answer to Question B?');
    });

    it('approves an answer so it no longer expires, and drops it from the unreviewed list', function () {
        $b = CachedAnswer::where('question', 'Question B?')->first();
        $b->update(['expires_at' => now()->addDays(30)]);

        $this->artisan('cached-answers:review', ['--approve' => [$b->id]])->expectsOutputToContain('Approved 1, rejected 0.')->assertSuccessful();

        expect($b->fresh()->status)->toBe('approved')
            ->and($b->fresh()->expires_at)->toBeNull();

        Artisan::call('cached-answers:review');

        expect(Artisan::output())->not->toContain('Question B?');
    });

    it('rejects an answer so it is never served again', function () {
        $a = CachedAnswer::where('question', 'Question A?')->first();

        $this->artisan('cached-answers:review', ['--reject' => [$a->id]])->expectsOutputToContain('Approved 0, rejected 1.')->assertSuccessful();

        expect($a->fresh()->status)->toBe('rejected');
    });

    it('lists approved answers on request, and says when there is nothing', function () {
        $this->artisan('cached-answers:review', ['--status' => 'approved'])->expectsOutputToContain('No approved answers to review.')->assertSuccessful();
    });
});

describe('ai:stats', function () {
    function loggedCall(array $overrides = []): ModelCall
    {
        return ModelCall::create($overrides + [
            'feature' => 'support.reply', 'provider' => 'ollama', 'model' => 'llama3.1:8b',
            'prompt_tokens' => 1000, 'completion_tokens' => 50, 'finish_reason' => 'stop', 'latency_ms' => 800,
            'cached_tokens' => 700, 'ttft_ms' => 1800, 'semantic_cache_hit' => null,
            'context_breakdown' => ['system' => 440, 'memories' => 90, 'summary' => 300, 'history' => 100, 'context' => 40, 'question' => 30],
        ]);
    }

    it('prints the four numbers over the window and the week before', function () {
        loggedCall();
        loggedCall(['prompt_tokens' => 3000, 'cached_tokens' => 1000, 'ttft_ms' => 2200, 'context_breakdown' => ['system' => 440, 'history' => 2000, 'question' => 50]]);
        loggedCall(['semantic_cache_hit' => true, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'finish_reason' => 'cache', 'cached_tokens' => null, 'ttft_ms' => null, 'context_breakdown' => null]);
        loggedCall(['semantic_cache_hit' => false]);

        foreach (range(1, 7) as $i) {
            $old = loggedCall(['prompt_tokens' => 2000, 'cached_tokens' => 400]);

            ModelCall::whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(3)]);
        }

        Artisan::call('ai:stats', ['--feature' => 'support.reply', '--since' => '24h']);

        $output = Artisan::output();

        expect($output)->toMatch('/calls\s+4\s+1\b/')                               // 4 in the window; 7 calls in the week before is one a day
            ->and($output)->toMatch('/prompt cache hit rate\s+48%\s+20%/')          // (700 + 1000 + 700) / (1000 + 3000 + 1000); the week before 400 / 2000
            ->and($output)->toMatch('/semantic cache hits\s+50%\s+n\/a/')
            ->and($output)->toMatch('/tokens \/ answer\s+1,667\s+2,000/')           // (1000 + 3000 + 1000) / 3 answered by the model
            ->and($output)->toContain('by slot: system 440 · memories 60 · summary 200 · history 733 · context 27 · question 37')
            ->and($output)->toMatch('/time to first token\s+1,933 ms\s+1,800 ms/');
    });

    it('prints the citation flag rate from the calls the checker looked at', function () {
        loggedCall(['citation_errors' => 0]);
        loggedCall(['citation_errors' => 0]);
        loggedCall(['citation_errors' => 2]);
        loggedCall(['citation_errors' => null]);

        Artisan::call('ai:stats', ['--feature' => 'support.reply', '--since' => '24h']);

        expect(Artisan::output())->toMatch('/citation flag rate\s+33%\s+n\/a/');
    });

    it('says n/a rather than dividing by zero when there is no data', function () {
        Artisan::call('ai:stats');

        $output = Artisan::output();

        expect($output)->toMatch('/calls\s+0\s+0/')
            ->and($output)->toMatch('/prompt cache hit rate\s+n\/a/')
            ->and($output)->toMatch('/citation flag rate\s+n\/a/')
            ->and($output)->toContain('by slot: n/a');
    });

    it('filters by feature and by window', function () {
        loggedCall();
        loggedCall(['feature' => 'ticket.classify']);

        $stale = loggedCall();
        ModelCall::whereKey($stale->id)->toBase()->update(['created_at' => now()->subHours(30)]);

        Artisan::call('ai:stats', ['--feature' => 'support.reply', '--since' => '24h']);

        expect(Artisan::output())->toMatch('/calls\s+1\s/');

        Artisan::call('ai:stats', ['--feature' => 'support.reply', '--since' => '3d']);

        expect(Artisan::output())->toMatch('/calls\s+2\s/');
    });

    it('rejects a window it cannot read', function () {
        $this->artisan('ai:stats', ['--since' => 'yesterday'])->expectsOutputToContain('--since takes a number and a unit')->assertFailed();
    });
});
