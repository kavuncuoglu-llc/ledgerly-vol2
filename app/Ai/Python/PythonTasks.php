<?php

namespace App\Ai\Python;

use App\Models\HelpChunk;
use App\Models\PythonTask;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;

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

            app(Streams::class)->add($stream, ['task' => json_encode($payload, JSON_THROW_ON_ERROR)] + self::traceFields());
        });

        return $taskId;
    }

    // Chapter 31, Topic 6: the trace context rides in a field beside the task, not inside it (the contract is closed), and the worker links its batch span to it.
    public static function traceFields(): array
    {
        $carrier = [];

        TraceContextPropagator::getInstance()->inject($carrier);

        return isset($carrier['traceparent'])
            ? ['traceparent' => $carrier['traceparent']]
            : [];
    }

    // Chapter 30, Topic 3: a sample of answers (question, answer, the sources it was given) for the RAGAS judge. $items follow eval-sample.v1.
    public static function evalSample(array $items, string $judge): string
    {
        return self::queue('eval-sample.v1', 'eval-sample', (string) config('ai.python.streams.eval_sample'), $judge, $items);
    }

    // Chapter 30, Topic 3: finished conversations for DeepEval's conversational metrics. $items follow eval-conversations.v1.
    public static function evalConversations(array $items, string $judge): string
    {
        return self::queue('eval-conversations.v1', 'eval-conversations', (string) config('ai.python.streams.eval_sample'), $judge, $items);
    }

    private static function queue(string $schema, string $kind, string $stream, string $judge, array $items): string
    {
        $taskId = (string) Str::uuid();

        $payload = [
            'schema' => $schema,
            'task_id' => $taskId,
            'request_id' => Context::get('request_id'),
            'judge' => $judge,
            'items' => $items,
        ];

        Contract::assertValid($schema, $payload);

        DB::transaction(function () use ($taskId, $payload, $kind, $stream, $judge) {
            PythonTask::query()->create([
                'task_id' => $taskId,
                'kind' => $kind,
                'stream' => $stream,
                'model' => $judge,
                'status' => PythonTask::QUEUED,
                'item_count' => count($payload['items']),
                'request_id' => $payload['request_id'],
                'queued_at' => now(),
                'deadline_at' => now()->addSeconds((int) config('ai.python.task_timeout_seconds')),
            ]);

            app(Streams::class)->add($stream, ['task' => json_encode($payload, JSON_THROW_ON_ERROR)] + self::traceFields());
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
