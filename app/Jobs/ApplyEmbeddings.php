<?php

namespace App\Jobs;

use App\Models\HelpChunk;
use App\Models\PythonTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

// Python's answer, applied by PHP inside PHP's transaction. Idempotent on task_id: a duplicate delivery finds the task done and changes nothing (Topic 7).
class ApplyEmbeddings implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $result) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $task = PythonTask::query()->lockForUpdate()->find($this->result['task_id']);

            if ($task === null) {
                return;
            }

            if ($task->status === PythonTask::DONE) {
                return;
            }

            $this->apply($task);
        });
    }

    private function apply(PythonTask $task): void
    {
        $width = (int) config('ai.embed_dimensions');

        foreach ($this->result['items'] as $item) {
            if (count($item['vector']) !== $width) {
                $task->update([
                    'status' => PythonTask::FAILED,
                    'error' => 'item '.$item['id'].' has '.count($item['vector'])." dimensions, the vector column is {$width}",
                    'completed_at' => now(),
                ]);

                return;
            }
        }

        $existing = HelpChunk::query()
            ->whereIn('id', array_column($this->result['items'], 'id'))
            ->pluck('id')
            ->all();

        $applied = 0;

        foreach ($this->result['items'] as $item) {
            if (! in_array($item['id'], $existing, true)) {
                continue;                                              // the chunk was removed by a newer help:ingest while Python worked
            }

            DB::statement(
                'INSERT INTO help_chunk_vectors (help_chunk_id, model, task_id, embedding, created_at, updated_at)
                 VALUES (?, ?, ?, ?::vector, now(), now())
                 ON CONFLICT (help_chunk_id, model) DO UPDATE
                 SET task_id = EXCLUDED.task_id, embedding = EXCLUDED.embedding, updated_at = now()',
                [$item['id'], $this->result['model'], $task->task_id, '['.implode(',', $item['vector']).']'],
            );

            $applied++;
        }

        $task->update([
            'status' => PythonTask::DONE,
            'applied_count' => $applied,
            'elapsed_ms' => $this->result['elapsed_ms'],
            'error' => null,
            'completed_at' => now(),
        ]);
    }
}
