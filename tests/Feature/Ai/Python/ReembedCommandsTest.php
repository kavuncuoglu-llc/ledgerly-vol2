<?php

use App\Models\HelpChunk;
use App\Models\HelpChunkVector;
use App\Models\PythonTask;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->streams = fakeStreams();
});

function pyShadowVector(HelpChunk $chunk, array $vector, string $model = 'nomic-ai/nomic-embed-text-v1.5'): void
{
    DB::table('help_chunk_vectors')->insert([
        'help_chunk_id' => $chunk->id,
        'model' => $model,
        'task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11',
        'embedding' => '['.implode(',', $vector).']',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ai:reembed', function () {
    it('queues the embedded chunks in batches, one task per batch', function () {
        foreach (range(1, 5) as $i) {
            chunkRow();
        }

        $this->artisan('ai:reembed', ['--via' => 'python', '--model' => 'nomic-ai/nomic-embed-text-v1.5', '--batch' => 2])
            ->expectsOutputToContain('Queued 3 task(s) of up to 2 chunks (5 chunks)')
            ->assertSuccessful();

        expect($this->streams->length('py:tasks:embed'))->toBe(3)
            ->and(PythonTask::query()->count())->toBe(3)
            ->and(array_map('count', array_column(array_map(fn ($i) => $this->streams->decoded('py:tasks:embed', $i), [0, 1, 2]), 'items')))->toBe([2, 2, 1]);
    });

    it('skips parents that are only grounded on, and honors --limit', function () {
        chunkRow();
        chunkRow();
        chunkRow(['embedding' => null]);

        $this->artisan('ai:reembed', ['--limit' => 1])->expectsOutputToContain('(1 chunks)')->assertSuccessful();

        expect($this->streams->decoded('py:tasks:embed')['items'])->toHaveCount(1);
    });

    it('defaults to the model in config', function () {
        chunkRow();

        $this->artisan('ai:reembed')->assertSuccessful();

        expect($this->streams->decoded('py:tasks:embed')['model'])->toBe('nomic-ai/nomic-embed-text-v1.5');
    });

    it('refuses what the Build does not offer, and a batch the contract would reject', function () {
        chunkRow();

        $this->artisan('ai:reembed', ['--via' => 'ollama'])->assertFailed();
        $this->artisan('ai:reembed', ['--into' => 'live'])->assertFailed();
        $this->artisan('ai:reembed', ['--batch' => 257])->assertFailed();
        $this->artisan('ai:reembed', ['--batch' => 0])->assertFailed();

        expect($this->streams->length('py:tasks:embed'))->toBe(0);
    });

    it('asks for help:ingest first when there is nothing to embed', function () {
        $this->artisan('ai:reembed')->expectsOutputToContain('Run php artisan help:ingest first')->assertFailed();
    });

    it('treats a blank --model as the configured one', function () {
        chunkRow();

        $this->artisan('ai:reembed', ['--model' => ' '])->assertSuccessful();     // a blank option falls back to the configured model

        expect($this->streams->decoded('py:tasks:embed')['model'])->toBe('nomic-ai/nomic-embed-text-v1.5');
    });
});

describe('ai:compare-vectors', function () {
    it('reports the cosine between each chunk\'s Python vector and its live vector', function () {
        $same = chunkRow(['embedding' => array_merge([1.0], array_fill(0, 767, 0.0))]);
        $orthogonal = chunkRow(['embedding' => array_merge([1.0], array_fill(0, 767, 0.0))]);
        pyShadowVector($same, array_merge([1.0], array_fill(0, 767, 0.0)));
        pyShadowVector($orthogonal, array_merge([0.0, 1.0], array_fill(0, 766, 0.0)));

        $this->artisan('ai:compare-vectors', ['--model' => 'nomic-ai/nomic-embed-text-v1.5', '--against' => 'ollama'])
            ->expectsOutputToContain('chunks compared')
            ->expectsOutputToContain('0.0000')
            ->expectsOutputToContain('1.0000')
            ->expectsOutputToContain('Not ready to promote')
            ->assertSuccessful();
    });

    it('says it is ready when every chunk has a vector and none disagrees', function () {
        $chunk = chunkRow(['embedding' => array_merge([1.0], array_fill(0, 767, 0.0))]);
        pyShadowVector($chunk, array_merge([1.0], array_fill(0, 767, 0.0)));

        $this->artisan('ai:compare-vectors')
            ->expectsOutputToContain('Every embedded chunk has a shadow vector and none disagrees')
            ->assertSuccessful();
    });

    it('counts embedded chunks that have no shadow vector yet', function () {
        $chunk = chunkRow();
        chunkRow();
        pyShadowVector($chunk, $chunk->embedding);

        $this->artisan('ai:compare-vectors')->expectsOutputToContain('embedded chunks with no shadow vector')->expectsOutputToContain('Not ready to promote')->assertSuccessful();
    });

    it('asks for ai:reembed when there is nothing to compare, and takes only --against=ollama', function () {
        chunkRow();

        $this->artisan('ai:compare-vectors')->expectsOutputToContain('Run php artisan ai:reembed')->assertFailed();
        $this->artisan('ai:compare-vectors', ['--against' => 'live'])->assertFailed();
    });
});

describe('ai:promote-vectors', function () {
    it('replaces the live vectors with the shadow ones, in one transaction', function () {
        $a = chunkRow();
        $b = chunkRow();
        $hot = fn (int $i) => array_merge(array_fill(0, $i, 0.0), [1.0], array_fill(0, 767 - $i, 0.0));
        pyShadowVector($a, $hot(10));
        pyShadowVector($b, $hot(11));

        $this->artisan('ai:promote-vectors', ['--model' => 'nomic-ai/nomic-embed-text-v1.5'])
            ->expectsOutputToContain('Promoted 2 vector(s)')
            ->assertSuccessful();

        expect(HelpChunk::query()->find($a->id)->embedding[10])->toBe(1.0)
            ->and(HelpChunk::query()->find($b->id)->embedding[11])->toBe(1.0)
            ->and(HelpChunkVector::query()->count())->toBe(2);
    });

    it('refuses to mix two models in one column when a chunk has no shadow vector', function () {
        $a = chunkRow();
        $b = chunkRow();
        $before = $b->embedding;
        pyShadowVector($a, array_merge([1.0], array_fill(0, 767, 0.0)));

        $this->artisan('ai:promote-vectors')->expectsOutputToContain('1 embedded chunk(s) have no shadow vector')->assertFailed();

        expect(HelpChunk::query()->find($b->id)->embedding)->toBe($before)
            ->and(HelpChunk::query()->find($a->id)->embedding[0])->not->toBe(1.0);
    });

    it('refuses when there are no shadow vectors at all', function () {
        chunkRow();

        $this->artisan('ai:promote-vectors')->assertFailed();
    });

    it('leaves chunks that are only grounded on (no embedding) alone', function () {
        $a = chunkRow();
        $parent = chunkRow(['embedding' => null]);
        pyShadowVector($a, array_merge([1.0], array_fill(0, 767, 0.0)));

        $this->artisan('ai:promote-vectors')->assertSuccessful();

        expect(HelpChunk::query()->find($parent->id)->embedding)->toBeNull();
    });
});
