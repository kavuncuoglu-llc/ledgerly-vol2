<?php

use App\Ai\Retrieval\Eval\GoldenQuestion;
use App\Ai\Retrieval\Eval\GoldenSet;
use App\Ai\Retrieval\Eval\RetrievalEvaluator;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Help\HelpArticle;
use App\Models\HelpChunk;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function scriptedRetriever(array $answers): Retriever
{
    return new class($answers) implements Retriever
    {
        public function __construct(private array $answers) {}

        public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
        {
            return array_map(
                fn (string $article, int $index) => retrievedChunk(source: "help/{$article}.md", overrides: ['id' => $index + 1]),
                array_slice($this->answers[$question] ?? [], 0, $k),
                array_keys(array_slice($this->answers[$question] ?? [], 0, $k)),
            );
        }
    };
}

it('computes recall, precision and MRR at each cut-off, and checks the empty-expected questions', function () {
    $retriever = scriptedRetriever([
        'rank one' => ['billing/late-fees', 'a/x', 'a/y', 'a/z', 'a/w'],
        'rank three' => ['a/x', 'a/y', 'billing/late-fees', 'a/z', 'a/w'],
        'rank five' => ['a/x', 'a/y', 'a/z', 'a/w', 'billing/late-fees'],
        'never' => ['a/x', 'a/y', 'a/z', 'a/w', 'a/v'],
        'off-topic ok' => [],
        'off-topic wrong' => ['a/x'],
    ]);

    $report = (new RetrievalEvaluator($retriever))->run([
        new GoldenQuestion(1, 'rank one', ['billing/late-fees']),
        new GoldenQuestion(2, 'rank three', ['billing/late-fees']),
        new GoldenQuestion(3, 'rank five', ['billing/late-fees']),
        new GoldenQuestion(4, 'never', ['billing/late-fees']),
        new GoldenQuestion(5, 'off-topic ok', []),
        new GoldenQuestion(6, 'off-topic wrong', []),
    ], RetrievalScope::shared());

    expect($report->answerable)->toBe(4)
        ->and($report->recall)->toBe([1 => 0.25, 3 => 0.5, 5 => 0.75])
        ->and($report->precision[1])->toBe(0.25)
        ->and($report->precision[3])->toBe(round((1 / 3 + 1 / 3) / 4, 4))
        ->and($report->precision[5])->toBe(round(3 * 0.2 / 4, 4))
        ->and($report->mrr)->toBe(round((1 + 1 / 3 + 1 / 5) / 4, 4))
        ->and([$report->emptyCorrect, $report->emptyTotal])->toBe([1, 2])
        ->and($report->misses)->toHaveCount(1)
        ->and($report->misses[0]['id'])->toBe(4);
});

it('accepts any of several articles as the answer', function () {
    $report = (new RetrievalEvaluator(scriptedRetriever(['q' => ['b/two']])))->run([new GoldenQuestion(1, 'q', ['a/one', 'b/two'])], RetrievalScope::shared());

    expect($report->recallAt(1))->toBe(1.0);
});

it('reads a golden file and refuses a broken line', function () {
    $path = sys_get_temp_dir().'/golden-'.bin2hex(random_bytes(4)).'.jsonl';

    file_put_contents($path, '{"id":1,"question":"q","articles":["a/b"],"headings":["H"],"kind":"plain"}'."\n\n".'{"id":2,"question":"off","articles":[]}'."\n");

    $questions = GoldenSet::load($path);

    expect($questions)->toHaveCount(2)
        ->and($questions[0]->articles)->toBe(['a/b'])
        ->and($questions[1]->expectsNothing())->toBeTrue();

    file_put_contents($path, '{"id":1}');

    expect(fn () => GoldenSet::load($path))->toThrow(InvalidArgumentException::class);
});

describe('the retrieval:eval command (deterministic fake embeddings, a tiny library)', function () {
    beforeEach(function () {
        $this->directory = sys_get_temp_dir().'/eval-'.bin2hex(random_bytes(4));

        config(['ai.retrieval.eval_dir' => $this->directory, 'ai.retrieval.threshold' => 0.3, 'ledgerly.help_version' => 'v1']);

        helpLibrary([
            'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## When a late fee is applied\n\nA late fee is applied automatically thirty days after the due date unless the invoice is disputed.\n"),
            'invoices/recurring.md' => helpArticleSource('Recurring invoices', 'invoices', "## Repeat every month\n\nPick monthly and the same invoice is created again every month on the schedule you choose.\n"),
        ]);

        fakeEmbeddings();

        $this->artisan('help:ingest')->assertSuccessful();

        $this->golden = $this->directory.'/golden.jsonl';

        File::ensureDirectoryExists($this->directory);

        file_put_contents($this->golden, implode("\n", [
            json_encode(['id' => 1, 'question' => 'when is a late fee applied after the due date', 'articles' => ['billing/late-fees']]),
            json_encode(['id' => 2, 'question' => 'make the same invoice repeat every month', 'articles' => ['invoices/recurring']]),
            json_encode(['id' => 3, 'question' => 'zzzz qqqq', 'articles' => []]),
            json_encode(['id' => 4, 'question' => 'how do I rotate my password', 'articles' => ['billing/late-fees']]),
        ])."\n");
    });

    afterEach(function () {
        File::deleteDirectory($this->directory);
    });

    it('prints the config line, the table, the empty-expected check and the misses, and stores the run', function () {
        $this->artisan('retrieval:eval', ['--golden' => $this->golden])
            ->expectsOutputToContain('config: hybrid · threshold 0.30 · k 5 · rrf 60 · rerank off · rewrite off · nomic-embed-text · chunks by heading · help v1')
            ->expectsOutputToContain('recall')
            ->expectsOutputToContain('@5')
            ->expectsOutputToContain('empty-expected correct:  1/1')
            ->expectsOutputToContain('misses (1): #4')
            ->assertSuccessful();

        expect(glob($this->directory.'/runs/*-hybrid.json'))->toHaveCount(1)
            ->and(glob($this->directory.'/misses-*.json'))->toHaveCount(1)
            ->and(json_decode(file_get_contents(glob($this->directory.'/runs/*.json')[0]), true)['recall']['5'])->toBe(0.6667);
    });

    it('compares a second run with the first', function () {
        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--mode' => 'keyword'])->assertSuccessful();

        $this->artisan('retrieval:eval', ['--golden' => $this->golden])
            ->expectsOutputToContain('vs previous run (keyword): recall@5')
            ->assertSuccessful();
    });

    it('runs vector only and keyword only for Exercise 1', function () {
        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--mode' => 'vector', '--no-store' => true])
            ->expectsOutputToContain('config: vector')
            ->assertSuccessful();

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--mode' => 'keyword', '--no-store' => true])
            ->expectsOutputToContain('config: keyword')
            ->assertSuccessful();

        expect(File::exists($this->directory.'/runs'))->toBeFalse();
    });

    it('rejects an unknown mode and a missing file', function () {
        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--mode' => 'telepathy', '--no-store' => true])->assertFailed();

        $this->artisan('retrieval:eval', ['--golden' => '/nonexistent.jsonl'])->assertFailed();
    });

    it('asks for help:ingest first when the table is empty', function () {
        HelpChunk::query()->delete();

        $this->artisan('retrieval:eval', ['--golden' => $this->golden])
            ->expectsOutputToContain('Run php artisan help:ingest first')
            ->assertFailed();
    });
});

describe('retrieval:eval for Chapter 16: stages and candidate embedding models', function () {
    beforeEach(function () {
        $this->directory = sys_get_temp_dir().'/eval16-'.bin2hex(random_bytes(4));

        config(['ai.retrieval.eval_dir' => $this->directory, 'ai.retrieval.threshold' => 0.3, 'ledgerly.help_version' => 'v1', 'ai.sidecar.url' => 'http://sidecar.test']);

        helpLibrary([
            'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## When a late fee is applied\n\nA late fee is applied automatically thirty days after the due date unless the invoice is disputed.\n"),
            'invoices/recurring.md' => helpArticleSource('Recurring invoices', 'invoices', "## Repeat every month\n\nPick monthly and the same invoice is created again every month on the schedule you choose.\n"),
        ]);

        fakeEmbeddings();

        $this->artisan('help:ingest')->assertSuccessful();

        $this->golden = $this->directory.'/golden.jsonl';

        File::ensureDirectoryExists($this->directory);

        file_put_contents($this->golden, implode("\n", [
            json_encode(['id' => 1, 'question' => 'when is a late fee applied after the due date', 'articles' => ['billing/late-fees']]),
            json_encode(['id' => 2, 'question' => 'make the same invoice repeat every month', 'articles' => ['invoices/recurring']]),
        ])."\n");
    });

    afterEach(function () {
        File::deleteDirectory($this->directory);
    });

    it('runs the reranked pipeline with a pool of its own and says so', function () {
        Http::fake(['sidecar.test/rerank' => fn ($request) => Http::response(['scores' => array_fill(0, count($request['passages']), 1.0)])]);

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--reranker' => 'sidecar', '--pool' => 10, '--no-store' => true])
            ->expectsOutputToContain('rerank sidecar (pool 10)')
            ->assertSuccessful();
    });

    it('still evaluates, and says the reranker is on, when the sidecar is down', function () {
        Http::fake(['sidecar.test/*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--reranker' => 'sidecar', '--no-store' => true])
            ->expectsOutputToContain('rerank sidecar')
            ->expectsOutputToContain('empty-expected correct:  0/0')
            ->assertSuccessful();
    });

    it('names the rewrite mode in the config line and in the comparison with the previous run', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('late fees'))]);

        $this->artisan('retrieval:eval', ['--golden' => $this->golden])->assertSuccessful();

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--rewrite' => 'rewrite'])
            ->expectsOutputToContain('rewrite rewrite')
            ->expectsOutputToContain('vs previous run (hybrid)')
            ->assertSuccessful();
    });

    it('compares another embedding model on a shadow table and drops it afterwards', function () {
        Http::swap(new Factory);

        Http::fake(['*/embeddings' => fn ($request) => Http::response(['data' => [['embedding' => fakeEmbeddingVector($request['input'], $request['model'] === 'bge-m3' ? 1024 : 768)]]])]);

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--embed-model' => 'bge-m3', '--reindex-to' => 'bench_bge'])
            ->expectsOutputToContain('recall@5 (stage 1)')
            ->expectsOutputToContain('bge-m3  ')        // the model and its 1024 dimensions share a row
            ->assertSuccessful();

        expect(Schema::hasTable('bench_bge'))->toBeFalse()
            ->and(HelpChunk::count())->toBe(2)
            ->and(HelpChunk::first()->embedding)->toHaveCount(768);                         // the real index was never touched
    });

    it('can keep the shadow table and search it with the other width', function () {
        Http::swap(new Factory);

        Http::fake(['*/embeddings' => fn ($request) => Http::response(['data' => [['embedding' => fakeEmbeddingVector($request['input'], 384)]]])]);

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--embed-model' => 'small', '--reindex-to' => 'bench_small', '--keep-index' => true, '--k' => 1])->assertSuccessful();

        expect(Schema::hasTable('bench_small'))->toBeTrue()
            ->and(DB::table('bench_small')->count())->toBe(2);

        DB::statement('DROP TABLE bench_small');
    });

    it('needs a model name and a bench_ table name', function () {
        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--reindex-to' => 'bench_x'])
            ->expectsOutputToContain('--reindex-to needs --embed-model')
            ->assertFailed();

        $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--embed-model' => 'x', '--reindex-to' => 'users'])
            ->expectsOutputToContain('starts with bench_')
            ->assertFailed();
    });
});

describe('the real golden set', function () {
    beforeEach(function () {
        $this->questions = GoldenSet::load(base_path('storage/golden/retrieval.jsonl'));
    });

    it('has 120 questions, 12 of them expecting nothing', function () {
        expect($this->questions)->toHaveCount(120)
            ->and(collect($this->questions)->filter->expectsNothing()->count())->toBe(12)
            ->and(collect($this->questions)->pluck('id')->unique()->count())->toBe(120);
    });

    it('points every question at an article that exists in resources/help, under a heading that exists', function () {
        foreach ($this->questions as $question) {
            foreach ($question->articles as $article) {
                [$category, $slug] = explode('/', $article);

                $found = HelpArticle::find($category, $slug);

                expect($found)->not->toBeNull("Q{$question->id}: {$article} is not an article");
            }

            foreach ($question->headings as $heading) {
                $anywhere = collect($question->articles)->contains(function (string $article) use ($heading) {
                    [$category, $slug] = explode('/', $article);

                    return collect(HelpArticle::find($category, $slug)->headings())->pluck('text')->contains($heading);
                });

                expect($anywhere)->toBeTrue("Q{$question->id}: heading \"{$heading}\" is not in its articles");
            }
        }
    });

    it('covers most of the library and every category', function () {
        $articles = collect($this->questions)->flatMap->articles->unique();

        expect($articles->count())->toBeGreaterThan(80)
            ->and($articles->map(fn ($a) => explode('/', $a)[0])->unique()->count())->toBe(HelpArticle::categories()->count());
    });

    it('runs end to end over the real library with deterministic fake embeddings', function () {
        config(['ai.retrieval.eval_dir' => sys_get_temp_dir().'/eval-real-'.bin2hex(random_bytes(4)), 'ledgerly.help_version' => 'fake-embeddings']);

        fakeEmbeddings();

        $this->artisan('help:ingest')->assertSuccessful();

        $report = (new RetrievalEvaluator(app(Retriever::class)->with(['threshold' => 0.2])))->run($this->questions, RetrievalScope::shared());

        expect($report->answerable)->toBe(108)
            ->and($report->emptyTotal)->toBe(12)
            ->and($report->recallAt(5))->toBeGreaterThanOrEqual($report->recallAt(1))
            ->and($report->recallAt(5))->toBeGreaterThan(0.0)
            ->and($report->mrr)->toBeLessThanOrEqual(1.0);
    });
});
