<?php

namespace App\Ai\Batch;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Models\AiBatch;
use Throwable;

// Chapter 37, Topic 7: work with hours of slack runs on your own models when they are idle. Nothing is uploaded: submitting marks the batch, and each poll works through a share of the waiting requests on the batch's own role.
// The local GPU serves customers by day; a scheduled `ai:batch:poll` at night is what spends its spare capacity. The tokens are real, so the ledger gets them (at the batch rate of a role that has a price, nothing for one that is free).
final class LocalBatchDriver implements BatchDriver
{
    public function __construct(private AiManager $ai) {}

    public function submit(AiBatch $batch): string
    {
        return 'local-'.$batch->id;
    }

    public function status(AiBatch $batch): string
    {
        $pending = $batch->items()->where('status', 'pending')->count();

        if ($pending === 0) {
            return 'completed';
        }

        $this->work($batch, (int) config('ai.batch.local_per_poll', 25));

        return $batch->items()->where('status', 'pending')->exists()
            ? 'processing'
            : 'completed';
    }

    public function results(AiBatch $batch): iterable
    {
        foreach ($batch->items()->whereIn('status', ['ran', 'errored'])->cursor() as $item) {
            yield $item->status === 'ran'
                ? ['custom_id' => $item->custom_id, 'response' => $item->response]
                : ['custom_id' => $item->custom_id, 'error' => (string) $item->error];
        }
    }

    private function work(AiBatch $batch, int $limit): void
    {
        $model = $this->ai->chat($batch->provider);

        foreach ($batch->items()->where('status', 'pending')->limit($limit)->get() as $item) {
            try {
                $body = $item->request;

                $response = $model->chat(ChatRequest::make($body['messages'], array_filter(['max_tokens' => $body['max_tokens'] ?? null]), $batch->feature));

                $item->update(['status' => 'ran', 'response' => ['content' => $response->content, 'usage' => $response->usage->toArray(), 'model' => $response->model], 'input_tokens' => $response->usage->promptTokens, 'output_tokens' => $response->usage->completionTokens]);
            } catch (Throwable $e) {
                $item->update(['status' => 'errored', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            }
        }
    }
}
