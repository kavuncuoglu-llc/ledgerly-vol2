<?php

namespace App\Ai\Python;

use App\Models\HelpChunk;
use App\Models\PythonTask;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The Laravel side of the round trip (Figure 27-3): validate, record the task and its deadline, then XADD. Python never sees the database.
final class PythonTasks
{
    public const SCHEMA = 'embed-batch.v1';

    public static function embed(array $chunkIds, string $model): string
    {
        $taskId = (string) Str::uuid();

        $payload = [
            'schema' => self::SCHEMA,
            'task_id' => $taskId,
            'request_id' => Context::get('request_id'),
            'model' => $model,
            'items' => self::items($chunkIds),
        ];

        Contract::assertValid(self::SCHEMA, $payload);

        $stream = (string) config('ai.python.streams.embed');

        DB::transaction(function () use ($taskId, $payload, $model, $stream) {
            PythonTask::query()->create([
                'task_id' => $taskId,
                'kind' => 'embed',
                'stream' => $stream,
                'model' => $model,
                'status' => PythonTask::QUEUED,
                'item_count' => count($payload['items']),
                'request_id' => $payload['request_id'],
                'queued_at' => now(),
                'deadline_at' => now()->addSeconds((int) config('ai.python.task_timeout_seconds')),
            ]);

            app(Streams::class)->add($stream, ['task' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        });

        return $taskId;
    }

    private static function items(array $chunkIds): array
    {
        return HelpChunk::query()
            ->whereIn('id', $chunkIds)
            ->orderBy('id')
            ->get(['id', 'content', 'context_header'])
            ->map(fn (HelpChunk $chunk) => [
                'id' => $chunk->id,
                'text' => $chunk->context_header
                    ? $chunk->context_header."\n\n".$chunk->content
                    : $chunk->content,                              // what help:ingest embedded, so the two sets of vectors compare like with like
            ])
            ->all();
    }
}
