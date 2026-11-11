<?php

namespace App\Console\Commands;

use App\Evals\GoldenCase;
use App\Evals\ReviewQueue;
use Illuminate\Console\Command;

class EvalReviewQueue extends Command
{
    protected $signature = 'eval:review-queue {--list : Only list the open candidates}';

    protected $description = 'Label or dismiss the candidates eval:capture collected (Chapter 29, Topic 8)';

    public function handle(ReviewQueue $queue): int
    {
        $open = $queue->open();

        if ($open->isEmpty()) {
            $this->info('The review queue is empty.');

            return self::SUCCESS;
        }

        if ($this->option('list') || ! $this->input->isInteractive()) {
            $this->table(['id', 'signal', 'question'], $open->map(fn (array $row) => [$row['id'], $row['signal'], mb_strimwidth($row['question'], 0, 70, '…')])->all());

            return self::SUCCESS;
        }

        foreach ($open as $candidate) {
            $this->review($queue, $candidate);
        }

        return self::SUCCESS;
    }

    private function review(ReviewQueue $queue, array $candidate): void
    {
        $this->newLine();
        $this->line("<options=bold>{$candidate['id']}</>  ({$candidate['signal']})");
        $this->line('Q: '.$candidate['question']);
        $this->line('A: '.$candidate['answer']);

        $choice = $this->choice('Was this really wrong?', ['skip', 'dismiss', ...GoldenCase::BEHAVIORS], 'skip');

        if ($choice === 'skip') {
            return;
        }

        if ($choice === 'dismiss') {
            $queue->update($candidate['id'], ['status' => 'dismissed']);

            return;
        }

        $queue->update($candidate['id'], [
            'status' => 'labeled',
            'label' => [
                'expected_behavior' => $choice,
                'category' => (string) $this->ask('Category', 'billing'),
                'must_include' => $this->list((string) $this->ask('Facts a correct answer needs (comma separated)', '')),
                'must_not_include' => $this->list((string) $this->ask('Things it must not say (comma separated)', '')),
            ],
        ]);
    }

    private function list(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $text))));
    }
}
