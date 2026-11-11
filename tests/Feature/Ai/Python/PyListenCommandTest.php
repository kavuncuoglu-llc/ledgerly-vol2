<?php

use App\Jobs\ApplyEmbeddings;
use App\Models\HelpChunkVector;
use App\Models\PythonTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

function pyResultMessage(string $taskId, array $items, array $overrides = []): string
{
    return json_encode($overrides + [
        'schema' => 'embed-result.v1',
        'task_id' => $taskId,
        'request_id' => null,
        'model' => 'nomic-ai/nomic-embed-text-v1.5',
        'items' => $items,
        'elapsed_ms' => 5,
    ]);
}

function pyQueueTask(string $taskId = '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', string $status = 'queued', ?DateTimeInterface $deadline = null): PythonTask
{
    return PythonTask::query()->create([
        'task_id' => $taskId,
        'kind' => 'embed',
        'stream' => 'py:tasks:embed',
        'model' => 'nomic-ai/nomic-embed-text-v1.5',
        'status' => $status,
        'item_count' => 1,
        'queued_at' => now(),
        'deadline_at' => $deadline ?? now()->addMinutes(10),
    ]);
}

beforeEach(function () {
    $this->streams = fakeStreams();
});

it('dispatches the Laravel job that applies a valid result, then acknowledges it', function () {
    Bus::fake();
    pyQueueTask();
    $this->streams->add('py:results', ['task' => pyResultMessage('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', [['id' => 1, 'vector' => [0.5, 0.5]]])]);

    $this->artisan('py:listen', ['--once' => true])
        ->expectsOutputToContain('result 0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11 dispatched')
        ->assertSuccessful();

    Bus::assertDispatched(ApplyEmbeddings::class, fn (ApplyEmbeddings $job) => $job->result['task_id'] === '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11');

    expect($this->streams->pending('py:results', 'php-listeners'))->toBe(0);
});

it('applies the result end to end on a queue that runs at once', function () {
    $chunk = chunkRow();
    pyQueueTask();
    $vector = array_fill(0, 768, 0.0);
    $vector[7] = 1.0;
    $this->streams->add('py:results', ['task' => pyResultMessage('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', [['id' => $chunk->id, 'vector' => $vector]])]);

    $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

    expect(PythonTask::query()->first()->status)->toBe('done')
        ->and(HelpChunkVector::query()->count())->toBe(1);
});

it('does not dispatch a result that breaks the contract, fails its task, and acknowledges it', function () {
    Queue::fake();
    pyQueueTask();
    $this->streams->add('py:results', ['task' => pyResultMessage('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', [['id' => '1', 'vector' => [0.5]]])]);

    $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

    Queue::assertNothingPushed();

    expect(PythonTask::query()->first()->status)->toBe('failed')
        ->and(PythonTask::query()->first()->error)->toContain('embed-result.v1')
        ->and($this->streams->pending('py:results', 'php-listeners'))->toBe(0);
});

it('skips a result for a task it already applied', function () {
    Bus::fake();
    pyQueueTask(status: 'done');
    $this->streams->add('py:results', ['task' => pyResultMessage('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', [['id' => 1, 'vector' => [0.5]]])]);

    $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

    Bus::assertNothingDispatched();
});

it('ignores a result for a task this app never queued, and a message that is not JSON', function () {
    Bus::fake();
    $this->streams->add('py:results', ['task' => pyResultMessage('5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77', [['id' => 1, 'vector' => [0.5]]])]);
    $this->streams->add('py:results', ['task' => 'not json']);
    $this->streams->add('py:results', ['other' => 'no task field']);

    $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

    Bus::assertNothingDispatched();

    expect($this->streams->pending('py:results', 'php-listeners'))->toBe(0);
});

it('marks a task failed when the worker gives up and dead-letters it', function () {
    pyQueueTask();
    $this->streams->add('py:dead', ['task' => json_encode(['task_id' => '0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11']), 'error' => 'exceeded 3 attempts', 'stream' => 'py:tasks:embed']);

    $this->artisan('py:listen', ['--once' => true])->assertSuccessful();

    expect(PythonTask::query()->first()->status)->toBe('failed')
        ->and(PythonTask::query()->first()->error)->toBe('dead-lettered by the worker: exceeded 3 attempts')
        ->and($this->streams->pending('py:dead', 'php-listeners'))->toBe(0);
});

it('marks a task with no result after its deadline as failed, and leaves a fresh one alone', function () {
    pyQueueTask('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', deadline: now()->subMinute());
    pyQueueTask('5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77');

    $this->artisan('py:listen', ['--once' => true])
        ->expectsOutputToContain('1 task(s) passed their deadline')
        ->assertSuccessful();

    expect(PythonTask::query()->find('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11')->status)->toBe('failed')
        ->and(PythonTask::query()->find('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11')->error)->toBe('no result before the deadline')
        ->and(PythonTask::query()->find('5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77')->status)->toBe('queued');
});

it('reads the stream named by --stream', function () {
    Bus::fake();
    pyQueueTask();
    $this->streams->add('py:results:v2', ['task' => pyResultMessage('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11', [['id' => 1, 'vector' => [0.5]]])]);

    $this->artisan('py:listen', ['--once' => true, '--stream' => 'py:results:v2'])->assertSuccessful();

    Bus::assertDispatched(ApplyEmbeddings::class);
});

describe('py:status', function () {
    it('counts tasks by state and reports the streams, warning about the dead-letter stream', function () {
        pyQueueTask('0b0d4a52-3f7e-4c26-9a53-6b7d1d5e0a11');
        pyQueueTask('5d1e0e7e-4f1b-4a39-9c26-1d3a0a9d2f77', 'done');
        pyQueueTask('7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', 'failed');
        $this->streams->add('py:tasks:embed', ['task' => '{}']);
        $this->streams->add('py:dead', ['task' => '{}', 'error' => 'x', 'stream' => 'py:tasks:embed']);

        $this->artisan('py:status')
            ->expectsOutputToContain('queued')
            ->expectsOutputToContain('py:dead')
            ->expectsOutputToContain('1 message(s) in the dead-letter stream')
            ->assertSuccessful();
    });

    it('is quiet about the dead-letter stream when it is empty', function () {
        $this->artisan('py:status')->doesntExpectOutputToContain('dead-letter stream:')->assertSuccessful();
    });
});
