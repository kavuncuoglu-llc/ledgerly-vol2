<?php

use App\Ai\AiManager;
use App\Ai\Retrieval\Eval\PrecomputedEmbedder;
use App\Ai\Retrieval\Eval\QueryVectors;
use App\Facades\Ai;
use App\Models\Conversation;
use App\Models\HelpChunk;
use App\Models\Message;
use App\Models\MessageSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

// Chapter 46, Topic 6 and Figure 46-8: the pairs the embedding model is tuned on, and the check on its shadow vectors before they replace the live ones.
beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/emb-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->directory);

    config([
        'ai.retrieval.eval_dir' => $this->directory,
        'ai.retrieval.threshold' => 0.0,
        'ai.training.hash_key' => 'test-key',
        'ai.providers.mid-in-region.base_url' => 'http://mid-eu.test/v1',
        'ai.providers.mid-in-region.model' => 'llama3.1:70b',
        'ai.providers.mid-in-region.distillation_basis' => ['license' => 'Llama 3.1 Community License', 'reviewed' => '2026-10-28', 'by' => 'legal'],
        'ledgerly.help_version' => 'v1',
    ]);

    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## When a late fee is applied\n\nA late fee is applied automatically thirty days after the due date unless the invoice is disputed.\n"),
        'invoices/recurring.md' => helpArticleSource('Recurring invoices', 'invoices', "## Repeat every month\n\nPick monthly and the same invoice is created again every month on the schedule you choose.\n"),
        'billing/change-your-plan.md' => helpArticleSource('Change your plan', 'billing', "## Upgrading\n\nYou can upgrade at any time from Settings, then Plan.\n"),
    ]);

    fakeEmbeddings();

    $this->artisan('help:ingest')->assertSuccessful();

    $this->golden = $this->directory.'/golden.jsonl';

    File::put($this->golden, implode("\n", [
        json_encode(['id' => 1, 'question' => 'when is a late fee applied after the due date', 'articles' => ['billing/late-fees'], 'headings' => ['late fee is applied']]),
        json_encode(['id' => 2, 'question' => 'make the same invoice repeat every month', 'articles' => ['invoices/recurring']]),
        json_encode(['id' => 3, 'question' => 'an article that does not exist', 'articles' => ['nowhere/nothing']]),
    ])."\n");
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function shadowVectors(string $model, bool $good = true): void
{
    foreach (HelpChunk::query()->whereNotNull('embedding')->get() as $chunk) {
        DB::table('help_chunk_vectors')->insert([
            'help_chunk_id' => $chunk->id,
            'model' => $model,
            'task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11',
            'embedding' => '['.implode(',', $good ? $chunk->embedding : array_merge([1.0], array_fill(0, 767, 0.0))).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function queriesEmbeddedBy(string $kind): void
{
    app()->instance(QueryVectors::class, new class extends QueryVectors
    {
        public function embed(array $questions, string $model): array
        {
            return collect($questions)->mapWithKeys(fn ($q) => [$q => fakeEmbeddingVector($q)])->all();
        }
    });
}

it('hands the retriever the question vectors Python made, and refuses a question it did not embed', function () {
    $embedder = new PrecomputedEmbedder(app(AiManager::class), 'ledgerly-nomic-v1', ['a question' => [1.0, 0.0]]);

    expect($embedder->query('a question'))->toBe([1.0, 0.0])
        ->and(fn () => $embedder->query('another'))->toThrow(InvalidArgumentException::class, 'No precomputed vector');
});

it('runs one Python process over every question and reads the vectors back', function () {
    Process::fake(function ($process) {
        $out = $process->command[array_search('--out', $process->command, true) + 1];

        $in = $process->command[array_search('--questions', $process->command, true) + 1];

        File::put($out, json_encode(collect(json_decode(File::get($in), true))->mapWithKeys(fn ($q) => [$q => [0.5, 0.5]])->all()));

        return Process::result('2 questions embedded');
    });

    expect((new QueryVectors)->embed(['a', 'b', 'a'], 'ledgerly-nomic-v1'))->toBe(['a' => [0.5, 0.5], 'b' => [0.5, 0.5]]);

    Process::assertRan(fn ($process) => in_array('scripts/embed_queries.py', $process->command, true) && in_array('ledgerly-nomic-v1', $process->command, true));

    Process::fake(['*' => Process::result('', 'boom', 1)]);

    expect(fn () => (new QueryVectors)->embed(['a'], 'm'))->toThrow(RuntimeException::class, 'embed_queries.py failed: boom');
});

it('compares live and shadow on the golden questions and prints recall and rank', function () {
    shadowVectors('ledgerly-nomic-v1');

    queriesEmbeddedBy('tuned');

    $this->artisan('eval:retrieval', ['--model' => 'ledgerly-nomic-v1', '--golden' => $this->golden])
        ->expectsOutputToContain('shadow vectors: ledgerly-nomic-v1 (3 chunks)')
        ->expectsOutputToContain('recall@5')
        ->expectsOutputToContain('MRR')
        ->assertExitCode(0);

    expect(Schema::hasTable('bench_shadow_vectors'))->toBeFalse();
});

it('exits 1 when the shadow vectors do not gain what was asked of them, so the switch is not made', function () {
    shadowVectors('ledgerly-nomic-v1', good: false);

    queriesEmbeddedBy('tuned');

    $this->artisan('eval:retrieval', ['--model' => 'ledgerly-nomic-v1', '--golden' => $this->golden, '--min-gain' => '0.01'])->assertExitCode(1);
});

it('says how to make shadow vectors when there are none, and takes only live against shadow', function () {
    $this->artisan('eval:retrieval', ['--model' => 'ledgerly-nomic-v1', '--golden' => $this->golden])->expectsOutputToContain('ai:reembed --model=ledgerly-nomic-v1 --into=shadow')->assertExitCode(1);

    $this->artisan('eval:retrieval', ['--compare' => 'live,tuned'])->expectsOutputToContain('--compare=live,shadow is the only comparison')->assertExitCode(1);
});

it('writes pairs from the teacher\'s questions for every chunk, and the golden questions as the held-out set', function () {
    Ai::fake()->for('eval.synthesize')->respondWith('When does a late fee start?', 20);

    $out = $this->directory.'/retrieval';

    $this->artisan('dataset:pairs', ['--out' => $out, '--teacher' => 'mid-in-region', '--per-chunk' => 2, '--golden' => $this->golden])
        ->expectsOutputToContain('6 pairs (0 from confirmed answers, 6 teacher-written, 0 with a hard negative) · 2 held-out questions')
        ->assertExitCode(0);

    $pairs = collect(file($out.'/pairs.jsonl', FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

    $heldout = collect(file($out.'/heldout.jsonl', FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

    expect($pairs)->toHaveCount(6)
        ->and($pairs[0])->toHaveKeys(['anchor', 'positive', 'origin', 'chunk'])->and($pairs[0]['origin'])->toBe('teacher-written')
        ->and($heldout->pluck('id')->all())->toBe([1, 2])
        ->and($heldout[0]['relevant'])->toHaveCount(1)
        ->and(HelpChunk::query()->find($heldout[0]['relevant'][0])->source)->toBe('help/billing/late-fees.md');

    $chunks = collect(file($out.'/chunks.jsonl', FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

    expect($chunks)->toHaveCount(HelpChunk::query()->whereNull('tenant_id')->count())->and($chunks[0])->toHaveKeys(['id', 'text']);
});

it('refuses a teacher with no basis, and writes consented pairs with a hard negative when there is none to ask', function () {
    config(['ai.providers.mid-in-region.distillation_basis' => null]);

    $this->artisan('dataset:pairs', ['--out' => $this->directory.'/x', '--golden' => $this->golden])->expectsOutputToContain('no recorded basis')->assertExitCode(1);

    [$user] = customerWithInvoice('INV-4471');

    $user->tenant->update(['training_use' => true, 'training_terms_version' => 't1']);

    $conversation = Conversation::query()->create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'status' => 'open', 'prompt_version' => 'x', 'summary_through_seq' => 0]);

    [$cited, $notCited] = [HelpChunk::query()->where('source', 'help/billing/late-fees.md')->first(), HelpChunk::query()->where('source', 'help/invoices/recurring.md')->first()];

    $reply = Message::withoutGlobalScopes()->create(['conversation_id' => $conversation->id, 'tenant_id' => $user->tenant_id, 'role' => 'assistant', 'content' => 'Thirty days after.', 'question' => 'When does a late fee start? Mail maya@acme-studios.example.com', 'status' => 'completed', 'seq' => 2, 'tokens' => 10, 'feedback' => 'up', 'retrieved' => [['id' => $cited->id, 'eval_label' => 'x'], ['id' => $notCited->id, 'eval_label' => 'y']]]);

    MessageSource::withoutGlobalScopes()->create(['message_id' => $reply->id, 'tenant_id' => $user->tenant_id, 'help_chunk_id' => $cited->id, 'label' => 'l', 'source' => $cited->source, 'heading_path' => 'h', 'url' => 'u']);

    $out = $this->directory.'/consented';

    $this->artisan('dataset:pairs', ['--out' => $out, '--teacher' => 'none', '--golden' => $this->golden, '--patterns-only' => true])
        ->expectsOutputToContain('1 pairs (1 from confirmed answers, 0 teacher-written, 1 with a hard negative)')
        ->assertExitCode(0);

    $pair = json_decode(file($out.'/pairs.jsonl')[0], true);

    expect($pair['anchor'])->toContain('When does a late fee start?')->not->toContain('maya@acme-studios.example.com')
        ->and($pair['positive'])->toContain('late fee is applied')
        ->and($pair['negative'])->toContain('Pick monthly')
        ->and($pair['origin'])->toBe('consented');
});
