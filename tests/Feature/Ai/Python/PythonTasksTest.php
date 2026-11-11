<?php

use App\Ai\Python\ContractViolation;
use App\Ai\Python\PythonTasks;
use App\Ai\Python\Streams;
use App\Models\PythonTask;
use Illuminate\Support\Facades\Context;
use Tests\Support\FakeStreams;

beforeEach(function () {
    $this->streams = fakeStreams();
});

it('queues one embed-batch.v1 message on the tasks stream and records the task with a deadline', function () {
    $a = chunkRow(['content' => 'Late fees apply after thirty days.']);
    $b = chunkRow(['content' => 'Recurring invoices repeat.', 'context_header' => 'Invoices › Recurring']);

    $taskId = PythonTasks::embed([$b->id, $a->id], 'nomic-ai/nomic-embed-text-v1.5');

    $message = $this->streams->decoded('py:tasks:embed');

    expect($this->streams->length('py:tasks:embed'))->toBe(1)
        ->and($message['schema'])->toBe('embed-batch.v1')
        ->and($message['task_id'])->toBe($taskId)
        ->and($message['model'])->toBe('nomic-ai/nomic-embed-text-v1.5')
        ->and($message['items'])->toBe([
            ['id' => $a->id, 'text' => 'Late fees apply after thirty days.'],
            ['id' => $b->id, 'text' => "Invoices › Recurring\n\nRecurring invoices repeat."],
        ]);

    $task = PythonTask::query()->findOrFail($taskId);

    expect($task->status)->toBe('queued')
        ->and($task->item_count)->toBe(2)
        ->and($task->stream)->toBe('py:tasks:embed')
        ->and($task->deadline_at->isAfter(now()->addSeconds(590)))->toBeTrue();
});

it('carries the request correlation ID from Context into the message and the task', function () {
    Context::add('request_id', 'req-1234567890');

    $taskId = PythonTasks::embed([chunkRow()->id], 'nomic-ai/nomic-embed-text-v1.5');

    expect($this->streams->decoded('py:tasks:embed')['request_id'])->toBe('req-1234567890')
        ->and(PythonTask::query()->findOrFail($taskId)->request_id)->toBe('req-1234567890');
});

it('sends a null request_id when nothing started the work in a request', function () {
    PythonTasks::embed([chunkRow()->id], 'nomic-ai/nomic-embed-text-v1.5');

    expect($this->streams->decoded('py:tasks:embed')['request_id'])->toBeNull();
});

it('validates the outgoing task before anything touches the queue or the database', function () {
    expect(fn () => PythonTasks::embed([], 'nomic-ai/nomic-embed-text-v1.5'))->toThrow(ContractViolation::class);
    expect(fn () => PythonTasks::embed([chunkRow()->id], ''))->toThrow(ContractViolation::class);

    expect($this->streams->length('py:tasks:embed'))->toBe(0)
        ->and(PythonTask::query()->count())->toBe(0);
});

it('does not queue a task when the database write fails', function () {
    $id = chunkRow()->id;

    $streams = new class extends FakeStreams
    {
        public function add(string $stream, array $fields): string
        {
            throw new RuntimeException('redis is down');
        }
    };

    app()->instance(Streams::class, $streams);

    expect(fn () => PythonTasks::embed([$id], 'nomic-ai/nomic-embed-text-v1.5'))->toThrow(RuntimeException::class);

    expect(PythonTask::query()->count())->toBe(0);
});
