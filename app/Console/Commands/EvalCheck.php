<?php

namespace App\Console\Commands;

use App\Evals\ResultsChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class EvalCheck extends Command
{
    protected $signature = 'eval:check
        {results : The promptfoo results file (-o results.json)}
        {--quality=0.95 : The share of cases that must pass every quality gate}
        {--never=0 : Cases that may fail a never-gate}
        {--out=storage/eval/last-check.json : Where to write the summary eval:report reads}';

    protected $description = 'Apply the quality and never-gate thresholds to promptfoo results (Chapter 29)';

    public function handle(ResultsChecker $checker): int
    {
        $path = str_starts_with((string) $this->argument('results'), '/')
            ? (string) $this->argument('results')
            : base_path((string) $this->argument('results'));

        if (! File::exists($path)) {
            $this->error("No results file at {$path}.");

            return self::FAILURE;
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            $this->error('That file is not JSON.');

            return self::FAILURE;
        }

        try {
            $summary = $checker->check($decoded, (float) $this->option('quality'), (int) $this->option('never'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->print($summary);

        $this->save($summary);

        return $summary['passed']
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function print(array $summary): void
    {
        $this->line(sprintf(
            'quality gates   %d / %d pass all   (%.1f%%  ≥ %d%% required)   %s',
            $summary['quality_passes'],
            $summary['cases'],
            $summary['quality_pass_rate'] * 100,
            (int) round($summary['quality_threshold'] * 100),
            $summary['quality_ok'] ? '✓' : '✗',
        ));

        $this->line(sprintf(
            'never gates     %d / %d pass       (%d allowed failures)       %s',
            $summary['cases'] - $summary['never_failures'],
            $summary['cases'],
            $summary['never_allowed'],
            $summary['never_ok'] ? '✓' : '✗',
        ));

        foreach ($summary['by_source'] as $source => $row) {
            $this->line(sprintf('  %-11s %d cases, %.1f%% pass the quality gates', $source, $row['cases'], $row['quality_pass_rate'] * 100));
        }

        if ($summary['judge_pass_rate'] !== null) {
            $this->line(sprintf('judge           %.1f%% of %d cases pass the rubric (reported, not gated)', $summary['judge_pass_rate'] * 100, $summary['judge_cases']));
        }

        if ($summary['failing'] !== []) {
            $this->line('failing cases   '.collect($summary['failing'])->take(8)->map(fn (array $row) => $row['id'].' ('.mb_strimwidth($row['reason'], 0, 90, '…').')')->implode(', ').(count($summary['failing']) > 8 ? ', …' : ''));
        }
    }

    private function save(array $summary): void
    {
        $out = str_starts_with((string) $this->option('out'), '/')
            ? (string) $this->option('out')
            : base_path((string) $this->option('out'));

        File::ensureDirectoryExists(dirname($out));

        File::put($out, json_encode(['ran_at' => now()->toIso8601String(), 'kind' => 'promptfoo'] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
