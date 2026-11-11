<?php

use App\Jobs\ApplyEmbeddings;
use App\Models\HelpChunk;
use App\Models\HelpChunkVector;
use App\Models\PythonTask;
use Illuminate\Support\Facades\DB;

const TASK_ID = '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11';

const MODEL = 'nomic-ai/nomic-embed-text-v1.5';

function pyQueuedTask(int $items = 1, string $taskId = TASK_ID): PythonTask
{
    return PythonTask::query()->create([
        'task_id' => $taskId,
        'kind' => 'embed',
        'stream' => 'py:tasks:embed',
        'model' => MODEL,
        'status' => 'queued',
        'item_count' => $items,
        'queued_at' => now(),
        'deadline_at' => now()->addMinutes(10),
    ]);
}

function pyEmbedResult(array $items, string $taskId = TASK_ID): array
{
    return [
        'schema' => 'embed-result.v1',
        'task_id' => $taskId,
        'request_id' => null,
        'model' => MODEL,
        'items' => $items,
        'elapsed_ms' => 42,
    ];
}

function pyUnitVector(int $hot, int $dimensions = 768): array
{
    $vector = array_fill(0, $dimensions, 0.0);
    $vector[$hot] = 1.0;

    return $vector;
}

it('writes each vector to the shadow table and marks the task done', function () {
    $a = chunkRow();
    $b = chunkRow();
    pyQueuedTask(2);

    (new ApplyEmbeddings(pyEmbedResult([
        ['id' => $a->id, 'vector' => pyUnitVector(3)],
        ['id' => $b->id, 'vector' => pyUnitVector(4)],
    ])))->handle();

    $task = PythonTask::query()->findOrFail(TASK_ID);

    expect($task->status)->toBe('done')
        ->and($task->applied_count)->toBe(2)
        ->and($task->elapsed_ms)->toBe(42)
        ->and($task->completed_at)->not->toBeNull()
        ->and(HelpChunkVector::query()->count())->toBe(2)
        ->and(HelpChunkVector::query()->where('help_chunk_id', $a->id)->first()->embedding[3])->toBe(1.0)
        ->and(HelpChunk::query()->find($a->id)->embedding[3])->not->toBe(1.0);        // the live column is untouched
});

it('changes nothing when the same result arrives twice', function () {
    $a = chunkRow();
    pyQueuedTask();
    $result = pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3)]]);

    (new ApplyEmbeddings($result))->handle();

    $first = HelpChunkVector::query()->first();

    $result['items'][0]['vector'] = pyUnitVector(9);

    (new ApplyEmbeddings($result))->handle();

    expect(HelpChunkVector::query()->count())->toBe(1)
        ->and(HelpChunkVector::query()->first()->embedding[3])->toBe(1.0)
        ->and(HelpChunkVector::query()->first()->updated_at->equalTo($first->updated_at))->toBeTrue();
});

it('replaces the shadow vector when a newer task brings another one for the same chunk and model', function () {
    $a = chunkRow();
    pyQueuedTask(1, TASK_ID);
    pyQueuedTask(1, '5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77');

    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3)]])))->handle();
    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(5)]], '5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77')))->handle();

    expect(HelpChunkVector::query()->count())->toBe(1)
        ->and(HelpChunkVector::query()->first()->embedding[5])->toBe(1.0);
});

it('fails the task, and writes nothing, when a vector is not as wide as the column', function () {
    $a = chunkRow();
    pyQueuedTask();

    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3, 384)]])))->handle();

    $task = PythonTask::query()->findOrFail(TASK_ID);

    expect($task->status)->toBe('failed')
        ->and($task->error)->toContain('384 dimensions')
        ->and(HelpChunkVector::query()->count())->toBe(0);
});

it('skips a chunk that a newer help:ingest removed while Python was working', function () {
    $a = chunkRow();
    pyQueuedTask(2);

    (new ApplyEmbeddings(pyEmbedResult([
        ['id' => $a->id, 'vector' => pyUnitVector(3)],
        ['id' => 999999, 'vector' => pyUnitVector(4)],
    ])))->handle();

    expect(PythonTask::query()->findOrFail(TASK_ID)->applied_count)->toBe(1)
        ->and(HelpChunkVector::query()->count())->toBe(1);
});

it('ignores a result for a task this app never queued', function () {
    $a = chunkRow();

    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3)]])))->handle();

    expect(HelpChunkVector::query()->count())->toBe(0);
});

it('still applies a late result for a task that was already marked failed', function () {
    $a = chunkRow();
    pyQueuedTask()->update(['status' => 'failed', 'error' => 'no result before the deadline']);

    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3)]])))->handle();

    expect(PythonTask::query()->findOrFail(TASK_ID)->status)->toBe('done')
        ->and(PythonTask::query()->findOrFail(TASK_ID)->error)->toBeNull()
        ->and(DB::table('help_chunk_vectors')->count())->toBe(1);
});

it('removes the shadow vectors with their chunk', function () {
    $a = chunkRow();
    pyQueuedTask();

    (new ApplyEmbeddings(pyEmbedResult([['id' => $a->id, 'vector' => pyUnitVector(3)]])))->handle();

    $a->delete();

    expect(HelpChunkVector::query()->count())->toBe(0);
});
