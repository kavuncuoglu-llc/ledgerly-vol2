<?php

use App\Models\HelpChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/eval27-'.bin2hex(random_bytes(4));

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
    ])."\n");
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function pyCopyLiveVectorsToShadow(string $model): void
{
    foreach (HelpChunk::query()->whereNotNull('embedding')->get() as $chunk) {
        DB::table('help_chunk_vectors')->insert([
            'help_chunk_id' => $chunk->id,
            'model' => $model,
            'task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11',
            'embedding' => '['.implode(',', $chunk->embedding).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('runs the golden set on the Python vectors and drops its temporary table', function () {
    pyCopyLiveVectorsToShadow('nomic-ai/nomic-embed-text-v1.5');

    $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--vectors' => 'shadow'])
        ->expectsOutputToContain('Python vectors from nomic-ai/nomic-embed-text-v1.5: 2 chunk(s)')
        ->expectsOutputToContain('@5')
        ->assertSuccessful();

    $run = json_decode(file_get_contents(glob($this->directory.'/runs/*.json')[0]), true);

    expect($run['recall']['5'])->toEqual(1)
        ->and($run['vectors'])->toBe('shadow')
        ->and($run['embed_model'])->toBe('nomic-ai/nomic-embed-text-v1.5')
        ->and(Schema::hasTable('bench_python_vectors'))->toBeFalse();
});

it('scores worse when the Python vectors are worse, which is what the check is for', function () {
    foreach (HelpChunk::query()->get() as $chunk) {
        DB::table('help_chunk_vectors')->insert([
            'help_chunk_id' => $chunk->id,
            'model' => 'nomic-ai/nomic-embed-text-v1.5',
            'task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11',
            'embedding' => '['.implode(',', array_merge([1.0], array_fill(0, 767, 0.0))).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('retrieval:eval', ['--golden' => $this->golden])->assertSuccessful();
    $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--mode' => 'vector', '--vectors' => 'shadow'])->assertSuccessful();

    $runs = collect(glob($this->directory.'/runs/*.json'))->map(fn ($file) => json_decode(file_get_contents($file), true))->keyBy('vectors');

    expect($runs['live']['recall']['1'])->toEqual(1)
        ->and($runs['shadow']['recall']['1'])->toBeLessThan($runs['live']['recall']['1']);
});

it('asks for ai:reembed first when there are no Python vectors', function () {
    $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--vectors' => 'shadow'])
        ->expectsOutputToContain('Run php artisan ai:reembed --via=python first')
        ->assertFailed();

    expect(Schema::hasTable('bench_python_vectors'))->toBeFalse();
});

it('takes only live or shadow', function () {
    $this->artisan('retrieval:eval', ['--golden' => $this->golden, '--vectors' => 'cloud'])->assertFailed();
});

it('leaves the live run exactly as it was', function () {
    $this->artisan('retrieval:eval', ['--golden' => $this->golden])->assertSuccessful();

    $run = json_decode(file_get_contents(glob($this->directory.'/runs/*.json')[0]), true);

    expect($run['vectors'])->toBe('live')
        ->and($run['embed_model'])->toBe('nomic-embed-text');
});
