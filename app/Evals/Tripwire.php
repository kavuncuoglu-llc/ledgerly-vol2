<?php

namespace App\Evals;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 30, Topic 6: the same fixed benchmarks on the base model and on each fine-tune. If any score drops by more than a couple of points, look before you promote.
final class Tripwire
{
    /** The primary metric of each task in the fixed set (lm-evaluation-harness names, filter included). */
    public const METRICS = [
        'arc_easy' => 'acc_norm,none',
        'hellaswag' => 'acc_norm,none',
        'ifeval' => 'prompt_level_strict_acc,none',
        'gsm8k' => 'exact_match,strict-match',
    ];

    /** @return array<string, float> task => score as a percentage */
    public function scores(string $path): array
    {
        $file = $this->resultsFile($path);

        $results = json_decode(File::get($file), true)['results'] ?? throw new InvalidArgumentException("{$file} has no results.");

        $scores = [];

        foreach (self::METRICS as $task => $metric) {
            if (isset($results[$task][$metric])) {
                $scores[$task] = round($results[$task][$metric] * 100, 2);
            }
        }

        if ($scores === []) {
            throw new InvalidArgumentException("{$file} has none of the tripwire tasks (".implode(', ', array_keys(self::METRICS)).').');
        }

        return $scores;
    }

    /** @return list<array{task: string, base: float, candidate: float, drop: float, tripped: bool}> */
    public function compare(array $base, array $candidate, float $maxDrop): array
    {
        $rows = [];

        foreach ($base as $task => $score) {
            if (! isset($candidate[$task])) {
                throw new InvalidArgumentException("The candidate has no {$task} score: run the same task set on both.");
            }

            $drop = round($score - $candidate[$task], 2);

            $rows[] = ['task' => $task, 'base' => $score, 'candidate' => $candidate[$task], 'drop' => $drop, 'tripped' => $drop > $maxDrop];
        }

        return $rows;
    }

    // lm_eval --output_path DIR writes DIR/<model name>/results_<time>.json; a path to the file itself works too. The newest file wins.
    private function resultsFile(string $path): string
    {
        if (File::isFile($path)) {
            return $path;
        }

        $files = collect(File::isDirectory($path) ? File::allFiles($path) : [])
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'results_') && $file->getExtension() === 'json')
            ->sortByDesc(fn ($file) => $file->getMTime());

        if ($files->isEmpty()) {
            throw new InvalidArgumentException("No results_*.json under {$path}: run lm_eval with --output_path first.");
        }

        return $files->first()->getPathname();
    }
}
