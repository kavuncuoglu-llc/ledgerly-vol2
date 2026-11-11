<?php

namespace App\Ai\Batch;

use App\Ai\AiManager;
use App\Ai\Budgets\Teams;
use App\Ai\Ledger\Costing;
use App\Ai\Prompts\JudgePrompt;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use App\Models\OnlineJudgment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 37, Topic 7: in Laravel a batch is a small state machine. Build the request file from a query, submit it, poll on a schedule, collect. Collection is idempotent by the custom id, so a crash in the middle can be rerun;
// the items that failed go out again in a smaller follow-up batch; and every result is written to the ledger at the batch price (kind `batch`), so the saving shows up in showback.
final class BatchService
{
    public function __construct(private AiManager $ai, private Costing $costing) {}

    public function submit(string $name, string $spec, ?string $provider = null): AiBatch
    {
        $kind = config("ai.batch.kinds.{$name}") ?? throw new InvalidArgumentException("No batch kind [{$name}]: ".implode(', ', array_keys(config('ai.batch.kinds', []))).'.');

        $provider ??= (string) $kind['provider'];

        if (! $this->ai->configured($provider)) {
            throw new InvalidArgumentException("The provider [{$provider}] is not set up.");
        }

        $batch = AiBatch::create(['name' => $name, 'provider' => $provider, 'driver' => $this->driverName($provider), 'feature' => (string) $kind['feature'], 'status' => 'building']);

        $model = $this->ai->model($provider);

        $lines = [];

        try {
            foreach (app($kind['source'])->requests($spec) as $request) {
                $body = array_filter(['model' => $model, 'messages' => $request['messages'], 'max_tokens' => $request['max_tokens'] ?? null]);

                AiBatchItem::create(['batch_id' => $batch->id, 'custom_id' => $request['custom_id'], 'request' => $body]);

                $lines[] = json_encode(['custom_id' => $request['custom_id'], 'method' => 'POST', 'url' => '/v1/chat/completions', 'body' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } catch (InvalidArgumentException $e) {
            $batch->delete();                                                      // a source that cannot read its spec leaves no half-built batch behind

            throw $e;
        }

        if ($lines === []) {
            $batch->delete();

            throw new InvalidArgumentException("Nothing to batch for [{$spec}].");
        }

        $path = storage_path("app/batches/batch-{$batch->id}.jsonl");

        File::ensureDirectoryExists(dirname($path));

        File::put($path, implode("\n", $lines)."\n");

        return $this->send($batch, $path, count($lines));
    }

    /** @return AiBatch[] the batches whose state changed */
    public function poll(): array
    {
        $changed = [];

        foreach (AiBatch::query()->where('status', 'submitted')->get() as $batch) {
            $status = $this->driver($batch)->status($batch);

            if ($status === 'processing') {
                continue;
            }

            $batch->update(['status' => $status === 'completed' ? 'completed' : 'failed', 'completed_at' => now()]);

            $changed[] = $batch;
        }

        return $changed;
    }

    /** @return array{results: int, failed: int, resubmitted: ?AiBatch, ledger_rows: int, saving: ?float, batches: int} */
    public function collect(string $nameOrId): array
    {
        $batches = AiBatch::query()->where(fn ($q) => $q->where('name', $nameOrId)->orWhere('id', ctype_digit($nameOrId) ? (int) $nameOrId : 0))->where('status', 'completed')->get();

        if ($batches->isEmpty()) {
            throw new InvalidArgumentException("No completed batch named or numbered [{$nameOrId}] is waiting to be collected.");
        }

        $summary = ['results' => 0, 'failed' => 0, 'resubmitted' => null, 'ledger_rows' => 0, 'saving' => null, 'batches' => $batches->count()];

        $interactive = 0;

        $paid = 0;

        foreach ($batches as $batch) {
            [$done, $failed, $rows, $full, $discounted] = $this->collectOne($batch);

            $summary['results'] += $done + $failed;
            $summary['failed'] += $failed;
            $summary['ledger_rows'] += $rows;
            $interactive += $full;
            $paid += $discounted;

            if ($failed > 0) {
                $summary['resubmitted'] = $this->resubmit($batch);
            }
        }

        $summary['saving'] = $interactive > 0
            ? 1 - $paid / $interactive
            : null;

        return $summary;
    }

    private function send(AiBatch $batch, string $path, int $count): AiBatch
    {
        $batch->update(['input_path' => $path, 'request_count' => $count]);

        $id = $this->driver($batch)->submit($batch);

        $batch->update(['provider_batch_id' => $id, 'status' => 'submitted', 'submitted_at' => now()]);

        return $batch->fresh();
    }

    // @return array{0: int, 1: int, 2: int, 3: int, 4: int} done, failed, ledger rows written, what it would have cost interactively, what it cost at the batch price (micro-dollars)
    private function collectOne(AiBatch $batch): array
    {
        $done = 0;

        $failed = 0;

        $rows = 0;

        $full = 0;

        $paid = 0;

        $discount = (float) config('ai.batch.discount', 0.5);

        foreach ($this->driver($batch)->results($batch) as $result) {
            $item = AiBatchItem::query()->where('batch_id', $batch->id)->where('custom_id', $result['custom_id'])->first();

            if ($item === null || $item->status === 'done') {
                continue;                                                          // not ours, or collected before: collecting twice inserts nothing
            }

            if (isset($result['error'])) {
                $item->update(['status' => 'failed', 'error' => mb_substr($result['error'], 0, 250)]);

                $failed++;

                continue;
            }

            $usage = $result['response']['usage'] ?? [];

            $item->update(['status' => 'done', 'response' => $result['response'], 'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($usage['completion_tokens'] ?? 0)]);

            $this->afterCollect($batch, $item);

            $cost = $this->costing->of($batch->provider, (string) ($result['response']['model'] ?? $this->ai->model($batch->provider)), (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0));

            $micros = (int) round($cost['micros'] * (1 - $discount));

            $rows += DB::table('ai_ledger')->insertOrIgnore($this->ledgerRow($batch, $item, $usage, $micros, $cost['reason']));

            $full += $cost['micros'];

            $paid += $micros;

            $done++;
        }

        $batch->update(['status' => 'collected', 'collected_at' => now(), 'result_count' => $batch->result_count + $done, 'failed_count' => $failed]);

        return [$done, $failed, $rows, $full, $paid];
    }

    private function ledgerRow(AiBatch $batch, AiBatchItem $item, array $usage, int $micros, ?string $reason): array
    {
        return [
            'occurred_at' => now(),
            'entry_id' => "batch:{$batch->id}:{$item->custom_id}",
            'feature' => $batch->feature,
            'team' => Teams::of($batch->feature),
            'provider' => $batch->provider,
            'model' => (string) ($item->response['model'] ?? $this->ai->model($batch->provider)),
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'cached_input_tokens' => 0,
            'cache_write_tokens' => 0,
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'price_version' => $this->costing->version(),
            'cost_micro_usd' => $micros,
            'kind' => 'batch',
            'reason' => $reason ?? "batch {$batch->name}",
        ];
    }

    // The failures go out again, once, in a smaller batch of their own.
    private function resubmit(AiBatch $batch): AiBatch
    {
        $failed = $batch->items()->where('status', 'failed')->get();

        $next = AiBatch::create(['name' => $batch->name, 'provider' => $batch->provider, 'driver' => $batch->driver, 'feature' => $batch->feature, 'status' => 'building', 'parent_id' => $batch->id]);

        $lines = [];

        foreach ($failed as $item) {
            AiBatchItem::create(['batch_id' => $next->id, 'custom_id' => $item->custom_id, 'request' => $item->request]);

            $lines[] = json_encode(['custom_id' => $item->custom_id, 'method' => 'POST', 'url' => '/v1/chat/completions', 'body' => $item->request], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $path = storage_path("app/batches/batch-{$next->id}.jsonl");

        File::put($path, implode("\n", $lines)."\n");

        return $this->send($next, $path, count($lines));
    }

    // What a result is for. The nightly judge's scores land with the other online judgments; everything else stays on the item for whoever asked for it.
    private function afterCollect(AiBatch $batch, AiBatchItem $item): void
    {
        if ($batch->name !== 'judge-nightly' || ! str_starts_with($item->custom_id, 'reply:')) {
            return;
        }

        $score = JudgePrompt::parse((string) ($item->response['content'] ?? ''));

        if ($score === null) {
            return;
        }

        $id = (int) substr($item->custom_id, 6);

        OnlineJudgment::query()->updateOrCreate(['subject' => 'message:'.$id, 'metric' => 'batch_judge', 'task_id' => 'batch-'.$batch->id], ['message_id' => $id, 'score' => $score / 5, 'judge' => $batch->provider]);
    }

    public function driver(AiBatch $batch): BatchDriver
    {
        return match ($batch->driver) {
            'openai' => app(OpenAiBatchDriver::class),
            'fake' => app(FakeBatchDriver::class),
            default => app(LocalBatchDriver::class),
        };
    }

    private function driverName(string $provider): string
    {
        $configured = config("ai.providers.{$provider}.batch_driver");

        if ($configured !== null) {
            return (string) $configured;
        }

        return config("ai.providers.{$provider}.hosted")
            ? 'openai'
            : 'local';
    }
}
