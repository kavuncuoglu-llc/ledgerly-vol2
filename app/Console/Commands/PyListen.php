<?php

namespace App\Console\Commands;

use App\Ai\Python\Contract;
use App\Ai\Python\ContractViolation;
use App\Ai\Python\Streams;
use App\Jobs\ApplyEmbeddings;
use App\Jobs\ApplyEvalScores;
use App\Models\PythonTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;

class PyListen extends Command
{
    protected $signature = 'py:listen
        {--stream= : The results stream (default: config ai.python.streams.results)}
        {--group= : The consumer group (default: config ai.python.listener_group)}
        {--block=5000 : Milliseconds to wait for a result before sweeping deadlines}
        {--once : Handle what is waiting, sweep deadlines, and stop}';

    protected $description = 'Read Python results from Redis and dispatch the Laravel job that applies each one (Chapter 27)';

    public function handle(Streams $streams): int
    {
        $results = (string) ($this->option('stream') ?: config('ai.python.streams.results'));

        $dead = (string) config('ai.python.streams.dead');

        $group = (string) ($this->option('group') ?: config('ai.python.listener_group'));

        $consumer = gethostname().':'.getmypid();

        $streams->ensureGroup($results, $group);
        $streams->ensureGroup($dead, $group);

        $this->info("Listening on {$results} and {$dead} as {$group}/{$consumer}");

        do {
            $this->results($streams, $results, $group, $consumer);

            $this->deadLetters($streams, $dead, $group, $consumer);

            $this->failLateTasks();
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function results(Streams $streams, string $stream, string $group, string $consumer): void
    {
        $entries = $streams->readGroup($stream, $group, $consumer, 16, $this->option('once') ? 0 : (int) $this->option('block'));

        foreach ($entries as $entry) {
            $this->dispatchResult($entry['fields']['task'] ?? '');

            $streams->ack($stream, $group, $entry['id']);              // dispatched or rejected: either way this entry is done
        }
    }

    private function dispatchResult(string $raw): void
    {
        try {
            $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('py:listen: a result is not JSON', ['error' => $e->getMessage()]);

            return;
        }

        $schema = $result['schema'] ?? '';

        if (! in_array($schema, ['embed-result.v1', 'eval-scores.v1'], true)) {
            $this->failTask(is_array($result) ? (string) ($result['task_id'] ?? '') : '', "unknown result schema '{$schema}'");

            return;
        }

        try {
            Contract::assertValid($schema, $result);
        } catch (ContractViolation $e) {
            $this->failTask($result['task_id'] ?? '', $e->getMessage());

            return;
        }

        $task = PythonTask::query()->find($result['task_id']);

        if ($task === null) {
            Log::warning('py:listen: a result for a task this app never queued', ['task_id' => $result['task_id']]);

            return;
        }

        if ($task->status === PythonTask::DONE) {
            return;                                                    // a duplicate delivery (Topic 7): already applied
        }

        if ($schema === 'eval-scores.v1') {
            ApplyEvalScores::dispatch($result);
        }

        if ($schema === 'embed-result.v1') {
            ApplyEmbeddings::dispatch($result);
        }

        $this->line("result {$result['task_id']} dispatched ({$task->item_count} items)");
    }

    private function deadLetters(Streams $streams, string $stream, string $group, string $consumer): void
    {
        $entries = $streams->readGroup($stream, $group, $consumer, 16);

        foreach ($entries as $entry) {
            $task = json_decode($entry['fields']['task'] ?? '', true);

            $this->failTask(
                is_array($task)
                    ? (string) ($task['task_id'] ?? '')
                    : '',
                'dead-lettered by the worker: '.($entry['fields']['error'] ?? 'unknown error'),
            );

            $streams->ack($stream, $group, $entry['id']);
        }
    }

    private function failTask(string $taskId, string $error): void
    {
        Log::warning('py:listen: task failed', ['task_id' => $taskId, 'error' => $error]);

        if ($taskId === '') {
            return;
        }

        PythonTask::query()
            ->whereKey($taskId)
            ->where('status', PythonTask::QUEUED)
            ->update(['status' => PythonTask::FAILED, 'error' => $error, 'completed_at' => now()]);
    }

    private function failLateTasks(): void
    {
        $late = PythonTask::query()
            ->where('status', PythonTask::QUEUED)
            ->where('deadline_at', '<', now())
            ->update(['status' => PythonTask::FAILED, 'error' => 'no result before the deadline', 'completed_at' => now()]);

        if ($late > 0) {
            $this->warn("{$late} task(s) passed their deadline with no result");
        }
    }
}
