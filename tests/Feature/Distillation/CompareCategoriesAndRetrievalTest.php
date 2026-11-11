<?php

use App\Ai\Retrieval\Eval\QueryVectors;
use App\Evals\GoldenSet;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\HelpChunk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Chapter 46, Topic 8 and Figure 46-8: eval:compare by category (howto, plans, integrations), and end to end on the live vectors against the shadow ones.
beforeEach(function () {
    config(['ai.eval.fixture_customers' => PHP_INT_MAX, 'ai.eval.enabled' => true, 'ai.distillation.categories' => ['plans' => ['articles' => ['billing/change-your-plan']], 'integrations' => ['articles' => ['integrations']]]]);

    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($customer)->create();

    $this->dir = sys_get_temp_dir().'/cmp-cat-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $cases = [
        ['id' => 'plans-a', 'category' => 'billing', 'expected_chunks' => ['help:billing/change-your-plan#upgrading'], 'question' => 'How do I upgrade my plan?'],
        ['id' => 'howto-a', 'category' => 'howto', 'expected_chunks' => ['help:invoices/export-invoices-csv#export'], 'question' => 'How do I export invoices?'],
        ['id' => 'hooks-a', 'category' => 'howto', 'expected_chunks' => ['help:integrations/webhooks#add-an-endpoint'], 'question' => 'How do I add a webhook?'],
        ['id' => 'late-a', 'category' => 'billing', 'expected_chunks' => ['help:billing/late-fees#how-the-fee-is-calculated'], 'question' => 'What is the late fee?'],
    ];

    File::put($this->dir.'/cases.jsonl', collect($cases)->map(fn ($c) => json_encode($c + ['source' => 'human', 'customer' => 'acme', 'expected_behavior' => 'answer', 'must_include' => ['1.5%']]))->implode("\n")."\n");

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));

    File::deleteDirectory(storage_path('eval'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::deleteDirectory(storage_path('eval'));
});

it('compares on the cases of a help-center area, or of a golden category', function (string $categories, int $count) {
    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $this->artisan('eval:compare', ['--set' => 'golden', '--categories' => $categories, '--roles' => 'local', '--with' => 'gates'])
        ->expectsOutputToContain("golden · {$count} cases · 1 roles")
        ->assertExitCode(0);
})->with([
    'plans: the articles the category names' => ['plans', 1],
    'integrations: a whole folder' => ['integrations', 1],
    'a golden category' => ['howto', 2],
    'two at once, once each' => ['plans,integrations', 2],
]);

it('says when a category has no cases', function () {
    $this->artisan('eval:compare', ['--set' => 'golden', '--categories' => 'refunds', '--roles' => 'local', '--with' => 'gates'])
        ->expectsOutputToContain('No golden cases in golden')
        ->assertExitCode(1);
});

it('runs every role on the live vectors and on the shadow ones, and labels each column', function () {
    config(['ai.retrieval.threshold' => 0.0, 'ledgerly.help_version' => 'v1']);

    helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\nThe fee is 1.5% a month, prorated by day.\n")]);

    fakeEmbeddings();

    $this->artisan('help:ingest')->assertSuccessful();

    foreach (HelpChunk::query()->whereNotNull('embedding')->get() as $chunk) {
        DB::table('help_chunk_vectors')->insert(['help_chunk_id' => $chunk->id, 'model' => 'ledgerly-nomic-v1', 'task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', 'embedding' => '['.implode(',', $chunk->embedding).']', 'created_at' => now(), 'updated_at' => now()]);
    }

    $questions = collect(['How do I upgrade my plan?', 'How do I export invoices?', 'How do I add a webhook?', 'What is the late fee?']);

    app()->instance(QueryVectors::class, new class($questions) extends QueryVectors
    {
        public array $asked = [];

        public function __construct(private $questions) {}

        public function embed(array $questions, string $model): array
        {
            $this->asked = [$model, $questions];

            return collect($questions)->mapWithKeys(fn ($q) => [$q => fakeEmbeddingVector($q)])->all();
        }
    });

    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $this->artisan('eval:compare', ['--set' => 'golden', '--roles' => 'local', '--with' => 'gates', '--retrieval' => 'live,shadow', '--retrieval-model' => 'ledgerly-nomic-v1', '--limit' => 2])
        ->expectsOutputToContain('local@live')
        ->expectsOutputToContain('local@shadow')
        ->assertExitCode(0);

    expect(Schema::hasTable('bench_shadow_vectors'))->toBeFalse()
        ->and(config('ai.retrieval.table'))->toBeNull();
});

it('refuses a shadow run with no shadow vectors, and says how to make them', function () {
    config(['ai.retrieval.threshold' => 0.0, 'ledgerly.help_version' => 'v1']);

    helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing')]);

    fakeEmbeddings();

    $this->artisan('help:ingest')->assertSuccessful();

    $this->artisan('eval:compare', ['--set' => 'golden', '--roles' => 'local', '--with' => 'gates', '--retrieval' => 'shadow', '--retrieval-model' => 'ledgerly-nomic-v1'])
        ->expectsOutputToContain('ai:reembed --model=ledgerly-nomic-v1 --into=shadow first')
        ->assertExitCode(1);

    expect(Schema::hasTable('bench_shadow_vectors'))->toBeFalse();
});

it('takes live, shadow or both for --retrieval and nothing else', function () {
    $this->artisan('eval:compare', ['--set' => 'golden', '--roles' => 'local', '--with' => 'gates', '--retrieval' => 'tuned'])->expectsOutputToContain('--retrieval takes live, shadow or live,shadow')->assertExitCode(1);
});
