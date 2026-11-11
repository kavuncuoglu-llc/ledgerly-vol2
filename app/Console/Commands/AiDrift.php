<?php

namespace App\Console\Commands;

use App\Ai\Drift\Drift;
use Illuminate\Console\Command;

class AiDrift extends Command
{
    protected $signature = 'ai:drift
        {--feature=support.reply : The feature to watch}
        {--window=7d : The recent period (7d, 24h not supported: whole days)}
        {--baseline=28d : The period before it that the window is compared with}';

    protected $description = 'Input, output and quality drift from the audit trail: category PSI and rolling z-scores (Chapter 31, Topic 8)';

    public function handle(Drift $drift): int
    {
        $window = $this->days((string) $this->option('window'));

        $baseline = $this->days((string) $this->option('baseline'));

        if ($window === null || $baseline === null) {
            $this->error('--window and --baseline are whole days: 7d, 28d.');

            return self::FAILURE;
        }

        $report = $drift->report((string) $this->option('feature'), $window, $baseline);

        $this->print($report);

        return self::SUCCESS;
    }

    private function print(array $report): void
    {
        $input = $report['input'];

        if ($input['psi'] === null) {
            $this->line('input    no category data in both periods (the router writes messages.category; is AI_ROUTER on?)');
        }

        if ($input['psi'] !== null) {
            $arrow = match ($input['verdict']) {
                'ACT' => '  ← ACT',
                'look' => '  ← look',
                default => '',
            };

            $moved = collect($input['moved'])->take(2)->map(fn (array $row) => sprintf('%s %d%% → %d%%', $row['category'], round($row['baseline'] * 100), round($row['current'] * 100)))->implode(', ');

            $this->line(sprintf('input    category PSI %.2f%s      %s', $input['psi'], $arrow, $moved));
        }

        $this->line('output   '.$this->metrics($report['output'], percent: ['decline rate', 'escalation rate']));

        $this->line('quality  '.$this->metrics($report['quality'], percent: ['thumbs-down']));

        foreach ($this->suggestions($report) as $line) {
            $this->line('suggest  '.$line);
        }

        $this->line(sprintf('(%d replies in the window, %d in the baseline)', $report['replies']['window'], $report['replies']['baseline']));
    }

    private function metrics(array $metrics, array $percent): string
    {
        if ($metrics === []) {
            return 'no data in both periods';
        }

        return collect($metrics)->map(function (array $row, string $name) use ($percent) {
            $format = in_array($name, $percent, true)
                ? fn (float $v) => number_format($v * 100, 1).'%'
                : fn (float $v) => number_format($v, 2);

            $alert = abs($row['z']) >= Drift::Z_ALERT
                ? '  ← ALERT'
                : '';

            return sprintf('%s %s → %s (z %.1f)%s', $name, $format($row['baseline']), $format($row['current']), $row['z'], $alert);
        })->implode('   ');
    }

    private function suggestions(array $report): array
    {
        $lines = [];

        if (($report['input']['verdict'] ?? '') === 'ACT') {
            $lines[] = 'the mix of questions moved: read a sample of the categories that grew from the audit trail and add golden cases for them';
        }

        if (abs($report['output']['decline rate']['z'] ?? 0) >= Drift::Z_ALERT) {
            $lines[] = 'the decline rate is outside its range: a help-center gap, or a retrieval change; find the questions that are being declined';
        }

        if (abs($report['quality']['sampled faithfulness']['z'] ?? 0) >= Drift::Z_ALERT) {
            $lines[] = 'sampled faithfulness moved: compare its answers by prompt version and by retrieved source before touching the prompt';
        }

        return $lines;
    }

    private function days(string $spec): ?int
    {
        return preg_match('/^(\d+)d$/', $spec, $m) === 1
            ? (int) $m[1]
            : null;
    }
}
