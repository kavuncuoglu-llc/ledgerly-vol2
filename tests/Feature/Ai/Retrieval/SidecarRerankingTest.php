<?php

use App\Ai\Contracts\Reranker;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\RerankingRetriever;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Sidecar\SidecarRejected;
use App\Ai\Sidecar\SidecarUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function refusingSidecar(): Closure
{
    $GLOBALS['sidecarAttempts'] = 0;

    return function () {
        $GLOBALS['sidecarAttempts']++;

        throw new ConnectionException('Connection refused');
    };
}

function sidecarCalls(): int
{
    return $GLOBALS['sidecarAttempts'];
}

function fixedRetriever(array $chunks): Retriever
{
    return new class($chunks) implements Retriever
    {
        public ?int $askedFor = null;

        public function __construct(private array $chunks) {}

        public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
        {
            $this->askedFor = $k;

            return array_slice($this->chunks, 0, $k);
        }
    };
}

function candidates(int $count): array
{
    return array_map(fn (int $n) => retrievedChunk("body {$n}", heading: "Article {$n} › Section {$n}", overrides: ['id' => $n]), range(1, $count));
}

describe('SidecarClient and SidecarReranker', function () {
    beforeEach(function () {
        $this->sidecar = new SidecarClient('http://sidecar.test', 1.0, 5.0, 30);
    });

    it('posts the question and the passages and returns one float score per passage, in order', function () {
        Http::fake(['sidecar.test/rerank' => Http::response(['scores' => [0.1, '0.9', 0.5]])]);

        $scores = (new SidecarReranker($this->sidecar))->score('late fee?', ['a', 'b', 'c']);

        expect($scores)->toBe([0.1, 0.9, 0.5]);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://sidecar.test/rerank'
            && $request['question'] === 'late fee?'
            && $request['passages'] === ['a', 'b', 'c']);
    });

    it('does not call the sidecar for nothing to score', function () {
        Http::fake();

        expect((new SidecarReranker($this->sidecar))->score('q', []))->toBe([]);

        Http::assertNothingSent();
    });

    it('treats a wrong number of scores as an outage', function () {
        Http::fake(['sidecar.test/rerank' => Http::response(['scores' => [0.1]])]);

        (new SidecarReranker($this->sidecar))->score('q', ['a', 'b']);
    })->throws(SidecarUnavailable::class, 'wrong number of scores');

    it('marks the sidecar down after a connection failure and stops trying for the cool-down', function () {
        Http::fake(['sidecar.test/*' => refusingSidecar()]);

        Log::spy();

        $reranker = new SidecarReranker($this->sidecar);

        expect(fn () => $reranker->score('q', ['a']))->toThrow(SidecarUnavailable::class, 'not reachable')
            ->and($this->sidecar->isDown())->toBeTrue();

        expect(fn () => $reranker->score('q', ['a']))->toThrow(SidecarUnavailable::class, 'cool-down')
            ->and(sidecarCalls())->toBe(1);

        Log::shouldHaveReceived('warning')->once();

        $this->sidecar->forgetOutage();

        expect($this->sidecar->isDown())->toBeFalse();
    });

    it('marks the sidecar down after a server error', function () {
        Http::fake(['sidecar.test/*' => Http::response('boom', 503)]);

        expect(fn () => $this->sidecar->json('/rerank', []))->toThrow(SidecarUnavailable::class, '503')
            ->and($this->sidecar->isDown())->toBeTrue();
    });

    it('does not mark the sidecar down for a request it rejected', function () {
        Http::fake(['sidecar.test/*' => Http::response(['detail' => 'question must not be empty'], 422)]);

        expect(fn () => $this->sidecar->json('/rerank', []))->toThrow(SidecarRejected::class, 'question must not be empty')
            ->and($this->sidecar->isDown())->toBeFalse();
    });

    it('reads /health', function () {
        Http::fake(['sidecar.test/health' => Http::response(['status' => 'ok', 'reranker_loaded' => true])]);

        expect($this->sidecar->health())->toBe(['status' => 'ok', 'reranker_loaded' => true]);
    });

    it('is built from config', function () {
        config(['ai.sidecar.url' => 'http://sidecar.test']);

        Http::fake(['sidecar.test/health' => Http::response(['status' => 'ok'])]);

        expect(SidecarClient::fromConfig()->health()['status'])->toBe('ok');
    });
});

describe('RerankingRetriever', function () {
    it('asks stage one for the wide pool and returns the five the reranker likes best', function () {
        $inner = fixedRetriever(candidates(20));

        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                return array_map(fn (string $text) => (float) preg_match_all('/body (3|7|11|13|17)\b/', $text), $passages);
            }
        };

        $result = (new RerankingRetriever($inner, $reranker, candidates: 20))->retrieve('q', RetrievalScope::shared(), 5);

        expect($inner->askedFor)->toBe(20)
            ->and(array_map(fn ($chunk) => $chunk->id, $result))->toBe([3, 7, 11, 13, 17]);
    });

    it('scores the child that matched, not the parent that will be shown', function () {
        $seen = [];

        $reranker = new class($seen) implements Reranker
        {
            public function __construct(public array &$seen) {}

            public function score(string $question, array $passages): array
            {
                $this->seen = $passages;

                return array_fill(0, count($passages), 0.5);
            }
        };

        $chunks = candidates(7);

        $chunks[0] = retrievedChunk('The whole parent section.', overrides: ['id' => 1, 'matched' => 'Late fees › How the fee is calculated — the rate and its base.']);

        (new RerankingRetriever(fixedRetriever($chunks), $reranker))->retrieve('q', RetrievalScope::shared(), 5);

        expect($seen[0])->toBe('Late fees › How the fee is calculated — the rate and its base.');
    });

    it('keeps the hybrid order for equal scores', function () {
        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                return array_fill(0, count($passages), 0.5);
            }
        };

        $result = (new RerankingRetriever(fixedRetriever(candidates(10)), $reranker))->retrieve('q', RetrievalScope::shared(), 3);

        expect(array_map(fn ($chunk) => $chunk->id, $result))->toBe([1, 2, 3]);
    });

    it('does not call the reranker when there is nothing to choose between', function () {
        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                throw new RuntimeException('should not be called');
            }
        };

        $result = (new RerankingRetriever(fixedRetriever(candidates(4)), $reranker))->retrieve('q', RetrievalScope::shared(), 5);

        expect($result)->toHaveCount(4);
    });

    it('applies an optional reranker floor, on the reranker\'s own scale', function () {
        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                return array_map(fn (int $i) => $i < 2 ? 3.0 : -4.0, array_keys($passages));
            }
        };

        $result = (new RerankingRetriever(fixedRetriever(candidates(10)), $reranker, floor: 0.0))->retrieve('q', RetrievalScope::shared(), 5);

        expect($result)->toHaveCount(2);
    });

    it('degrades to the hybrid order, with a warning, when the reranker fails', function () {
        $reranker = new class implements Reranker
        {
            public function score(string $question, array $passages): array
            {
                throw new SidecarUnavailable('connection refused');
            }
        };

        Log::spy();

        $result = (new RerankingRetriever(fixedRetriever(candidates(20)), $reranker))->retrieve('q', RetrievalScope::shared(), 5);

        expect(array_map(fn ($chunk) => $chunk->id, $result))->toBe([1, 2, 3, 4, 5]);

        Log::shouldHaveReceived('warning')->with('Reranker unavailable; using the hybrid order', Mockery::any());
    });
});

describe('the wired retriever with the real sidecar client', function () {
    beforeEach(function () {
        fakeEmbeddings();

        config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0, 'ai.retrieval.reranker' => 'sidecar', 'ai.sidecar.url' => 'http://sidecar.test']);

        foreach (range(1, 8) as $n) {
            chunkRow(['source' => "help/billing/article-{$n}.md", 'heading' => "Article {$n} › Fees", 'content' => "Late fee text number {$n}. A late fee is applied after the due date.", 'position' => $n]);
        }
    });

    it('is a RerankingRetriever over PgRetriever when the reranker is on, and plain PgRetriever when it is off', function () {
        expect(app(Retriever::class))->toBeInstanceOf(RerankingRetriever::class);

        config(['ai.retrieval.reranker' => 'none']);

        expect(app(Retriever::class))->toBeInstanceOf(PgRetriever::class);
    });

    it('reorders by the sidecar scores', function () {
        Http::fake([
            'sidecar.test/rerank' => fn (Request $request) => Http::response(['scores' => array_map(fn (string $text) => str_contains($text, 'number 6') ? 9.0 : 0.1, $request['passages'])]),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $result = app(Retriever::class)->retrieve('when is a late fee applied after the due date', RetrievalScope::shared(), 3);

        expect($result)->toHaveCount(3)
            ->and($result[0]->source)->toBe('help/billing/article-6.md');
    });

    it('still returns the hybrid answer, and does not wait for a timeout per question, when the sidecar is down', function () {
        Http::fake([
            'sidecar.test/*' => refusingSidecar(),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $question = 'when is a late fee applied after the due date';

        $first = app(Retriever::class)->retrieve($question, RetrievalScope::shared(), 3);

        $second = app(Retriever::class)->retrieve($question, RetrievalScope::shared(), 3);

        $hybrid = app(RetrieverFactory::class)->make(['reranker' => 'none'])->retrieve($question, RetrievalScope::shared(), 3);

        expect(array_map(fn ($c) => $c->id, $first))->toBe(array_map(fn ($c) => $c->id, $hybrid))
            ->and(array_map(fn ($c) => $c->id, $second))->toBe(array_map(fn ($c) => $c->id, $hybrid))
            ->and(sidecarCalls())->toBe(1);
    });

    it('lets the assistant answer when the sidecar is down', function () {
        Cache::flush();

        Http::fake([
            'sidecar.test/*' => refusingSidecar(),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
            '*/chat/completions' => Http::response(chatBody('I can\'t find that in the help center, so I can open a support ticket.')),
        ]);

        [$user] = customerWithInvoice();

        askAssistant($user, ['question' => 'when is a late fee applied after the due date'])
            ->assertOk()
            ->assertJsonPath('citations_ok', true);
    });
});
