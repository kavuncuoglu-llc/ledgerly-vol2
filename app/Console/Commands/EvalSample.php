<?php

namespace App\Console\Commands;

use App\Ai\Python\PythonTasks;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ShadowAnswerRecord;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class EvalSample extends Command
{
    protected $signature = 'eval:sample
        {--rate=0.02 : The share of answers to score (a stable hash of the id decides, so a rerun picks the same ones)}
        {--since=1d : How far back to look: 1d, 12h, 2w}
        {--to= : The stream (default py:eval-sample)}
        {--no-conversations : Only answers, not finished conversations}
        {--no-answers : Only finished conversations}';

    protected $description = 'Push a sample of yesterday\'s answers, with their sources, to the Python judge (Chapter 30, Topic 3)';

    public function handle(CurrentTenant $tenants): int
    {
        $since = $this->since((string) $this->option('since'));

        if ($since === null) {
            $this->error('--since wants a number and h, d or w: 12h, 1d, 2w.');

            return self::FAILURE;
        }

        $rate = (float) $this->option('rate');

        if ($rate <= 0 || $rate > 1) {
            $this->error('--rate is a share between 0 and 1.');

            return self::FAILURE;
        }

        if ($this->option('to')) {
            config(['ai.python.streams.eval_sample' => (string) $this->option('to')]);
        }

        $judge = (string) config('ai.python.judge.model');

        [$answers, $conversations] = $tenants->run(null, fn () => [
            $this->option('no-answers') ? collect() : $this->answers($since, $rate),
            $this->option('no-conversations') ? collect() : $this->conversations($since, $rate),
        ]);

        if ($answers->isEmpty() && $conversations->isEmpty()) {
            $this->info('Nothing to score: no answers in that window were picked at that rate.');

            return self::SUCCESS;
        }

        if ($answers->isNotEmpty()) {
            $task = PythonTasks::evalSample($answers->all(), $judge);

            $this->line("  task {$task}: {$answers->count()} answers → ".config('ai.python.streams.eval_sample'));
        }

        if ($conversations->isNotEmpty()) {
            $task = PythonTasks::evalConversations($conversations->all(), $judge);

            $this->line("  task {$task}: {$conversations->count()} finished conversations → ".config('ai.python.streams.eval_sample'));
        }

        $this->line('The Python worker scores them; php artisan py:listen applies the scores. Read them with php artisan eval:scorecard.');

        return self::SUCCESS;
    }

    /** @return Collection<int, array> */
    private function answers(CarbonInterface $since, float $rate): Collection
    {
        $live = Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->where('role', 'assistant')
            ->where('status', 'completed')
            ->where('updated_at', '>=', $since)
            ->whereNotNull('retrieved')
            ->orderBy('id')
            ->get()
            ->filter(fn (Message $message) => $message->retrieved !== [] && self::picked("message:{$message->id}", $rate))
            ->map(fn (Message $message) => $this->item("message:{$message->id}", (string) $message->question, (string) $message->content, $message));

        $shadow = ShadowAnswerRecord::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->filter(fn (ShadowAnswerRecord $record) => self::picked("shadow:{$record->id}", $rate))
            ->map(fn (ShadowAnswerRecord $record) => $this->item("shadow:{$record->id}", (string) $record->message->question, $record->content, $record->message));

        return $live->concat($shadow)->filter()->values();
    }

    private function item(string $id, string $question, string $answer, Message $source): ?array
    {
        $contexts = array_map(fn ($chunk) => $chunk->content, $source->retrievedSources());

        if ($question === '' || $answer === '' || $contexts === []) {
            return null;
        }

        return ['id' => $id, 'question' => $question, 'answer' => $answer, 'contexts' => $contexts, 'reference' => null];
    }

    /** @return Collection<int, array> */
    private function conversations(CarbonInterface $since, float $rate): Collection
    {
        return Conversation::withoutGlobalScopes()
            ->where('status', '!=', 'open')
            ->where('updated_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->filter(fn (Conversation $conversation) => self::picked("conversation:{$conversation->id}", $rate))
            ->map(function (Conversation $conversation) {
                $turns = Message::withoutGlobalScopes()
                    ->where('conversation_id', $conversation->id)
                    ->whereIn('role', ['user', 'assistant'])
                    ->where('status', 'completed')
                    ->whereNotNull('content')
                    ->orderBy('seq')
                    ->get(['role', 'content'])
                    ->map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content])
                    ->all();

                return count($turns) >= 2
                    ? ['id' => "conversation:{$conversation->id}", 'turns' => $turns]
                    : null;
            })
            ->filter()
            ->values();
    }

    public static function picked(string $subject, float $rate): bool
    {
        return hexdec(substr(hash('sha256', "sample:{$subject}"), 0, 8)) % 10000 < $rate * 10000;
    }

    private function since(string $spec): ?CarbonInterface
    {
        if (preg_match('/^(\d+)([hdw])$/', $spec, $m) !== 1) {
            return null;
        }

        return match ($m[2]) {
            'h' => now()->subHours((int) $m[1]),
            'd' => now()->subDays((int) $m[1]),
            'w' => now()->subWeeks((int) $m[1]),
        };
    }
}
