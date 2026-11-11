<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\OnlineJudgment;
use App\Models\PythonTask;
use App\Models\ShadowAnswerRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

// Chapter 30, Topic 3: the judge's scores, applied by PHP inside PHP's transaction. Idempotent on task_id like ApplyEmbeddings (Chapter 27, Topic 7).
class ApplyEvalScores implements ShouldQueue
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
        $applied = 0;

        foreach ($this->result['items'] as $item) {
            [$kind, $id] = explode(':', $item['id']);

            [$messageId, $variant] = $this->subject($kind, (int) $id);

            if ($messageId === null) {
                continue;                                                  // the reply was removed (retention, user:forget) while Python worked
            }

            foreach ($item['metrics'] as $metric => $score) {
                OnlineJudgment::query()->updateOrCreate(
                    ['subject' => $item['id'], 'metric' => $metric, 'task_id' => $task->task_id],
                    ['message_id' => $messageId, 'variant' => $variant, 'score' => $score, 'judge' => $this->result['judge']],
                );

                $applied++;
            }
        }

        $task->update([
            'status' => PythonTask::DONE,
            'applied_count' => $applied,
            'elapsed_ms' => $this->result['elapsed_ms'],
            'error' => null,
            'completed_at' => now(),
        ]);
    }

    /** @return array{0: int|null, 1: string|null} the message a score belongs to and its variant */
    private function subject(string $kind, int $id): array
    {
        if ($kind === 'shadow') {
            $shadow = ShadowAnswerRecord::query()->find($id);

            return [$shadow?->message_id, $shadow?->variant];
        }

        if ($kind === 'conversation') {
            $last = Message::withoutGlobalScopes()->where('conversation_id', $id)->whereNotNull('question')->orderByDesc('seq')->first();

            return [$last?->id, $last?->experiment_variant];
        }

        $message = Message::withoutGlobalScopes()->find($id);

        return [$message?->id, $message?->experiment_variant];
    }
}
