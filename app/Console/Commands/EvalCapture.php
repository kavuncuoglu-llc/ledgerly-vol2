<?php

namespace App\Console\Commands;

use App\Evals\CandidateCollector;
use App\Evals\ReviewQueue;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class EvalCapture extends Command
{
    protected $signature = 'eval:capture
        {--since=7d : How far back to look (7d, 24h, 2w)}
        {--signals=thumbs_down,citation_reject,escalated,judge_fail : Which signals mark a candidate}';

    protected $description = 'Collect production failures into the review queue (Chapter 29, Topic 8)';

    public function handle(CandidateCollector $collector, ReviewQueue $queue): int
    {
        $signals = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('signals')))));

        $unknown = array_diff($signals, CandidateCollector::SIGNALS);

        if ($unknown !== []) {
            $this->error('Unknown signal: '.implode(', ', $unknown).'. Use '.implode(', ', CandidateCollector::SIGNALS).'.');

            return self::FAILURE;
        }

        $since = $this->since((string) $this->option('since'));

        if ($since === null) {
            $this->error('--since wants a number and h, d or w: 24h, 7d, 2w.');

            return self::FAILURE;
        }

        $candidates = $collector->collect($since, $signals);

        $added = $queue->add($candidates->all());

        $counts = $candidates->countBy('signal');

        $this->line('  '.$candidates->count().' candidates · '.collect($signals)->map(fn (string $signal) => ($counts[$signal] ?? 0).' '.str_replace('_', ' ', $signal))->implode(' · '));
        $this->line("  {$added} new in the queue (".$queue->open()->count().' open). Label them with php artisan eval:review-queue.');

        return self::SUCCESS;
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
