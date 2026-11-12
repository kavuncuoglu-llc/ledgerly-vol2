<?php

namespace App\Console\Commands;

use App\Evals\Fairness\FairnessData;
use App\Evals\Fairness\FairnessReport;
use App\Evals\Fairness\FairnessSuite;
use App\Evals\Fairness\RewriteWriter;
use App\Evals\Generators\HelpCenterCases;
use App\Evals\Generators\QuestionWriter;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class EvalFairness extends Command
{
    protected $signature = 'eval:fairness
        {--base=golden:fairness-150 : The base set: golden:fairness-150 is tests/fairness/base.jsonl}
        {--dimensions=name,region,english,language,tier : What to vary, one detail at a time}
        {--runs= : Runs of each variant (default config fairness.runs, 3)}
        {--limit= : Only the first N base cases (a quick look; a sample this small proves nothing)}
        {--tolerance= : Points: the gap a dimension may show before it is flagged (default config fairness.tolerance, 2.0)}
        {--strict : Exit with failure when a dimension is flagged}
        {--json : Print the result as JSON}
        {--build-base : Write the base set from the golden sets and the help center instead of running the suite}
        {--size=150 : With --build-base, how many cases}
        {--build-rewrites : Draft the plain, non-native and language rewrites of the base cases with a local model (a person reads them)}
        {--writer= : The provider that drafts questions and rewrites (default config fairness.writer_provider)}';

    protected $description = 'Counterfactual fairness: copies of golden cases that differ in one irrelevant detail, compared in pairs with a bootstrap interval against a tolerance (Chapter 51, Topics 2 and 3)';

    public function handle(FairnessSuite $suite, FairnessData $data): int
    {
        try {
            if ($this->option('build-base')) {
                return $this->buildBase($data);
            }

            if ($this->option('build-rewrites')) {
                return $this->buildRewrites($data);
            }

            return $this->suite($suite, $data);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function suite(FairnessSuite $suite, FairnessData $data): int
    {
        $dimensions = $data->assertDimensions(array_values(array_filter(array_map('trim', explode(',', (string) $this->option('dimensions'))))));

        $base = $data->base((string) $this->option('base'));

        if ($this->option('limit') !== null) {
            $base = $base->take(max(1, (int) $this->option('limit')))->values();
        }

        $progress = fn (int $done, int $total) => $this->output->isVerbose() && $done % 25 === 0 ? $this->line("  {$done}/{$total}") : null;

        $result = $suite->run(
            $base,
            $dimensions,
            max(1, (int) ($this->option('runs') ?: config('fairness.runs'))),
            $this->option('tolerance') === null ? null : (float) $this->option('tolerance'),
            $progress,
        );

        FairnessReport::save($result);

        $this->option('json')
            ? $this->line((string) json_encode($result, JSON_PRETTY_PRINT))
            : $this->print($result);

        return $this->option('strict') && $result['flagged'] !== []
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function print(array $result): void
    {
        $this->line(sprintf(
            '%d base cases · %d dimensions · %s variant runs · paired bootstrap, %d%% intervals · tolerance %.1f points',
            $result['base_cases'],
            count($result['dimensions']),
            number_format($result['variant_runs']),
            (int) round($result['confidence'] * 100),
            $result['tolerance'],
        ));

        $this->table(
            ['dimension', 'measure', 'largest pairwise gap', '95% interval', 'verdict'],
            array_map(fn (array $row) => [
                $row['dimension'],
                $row['measure'].($row['primary'] ? '' : ' (also)'),
                sprintf('%+.1f pts (%s)', $row['gap'], $row['pair']),
                sprintf('[%+.1f, %+.1f]', $row['low'], $row['high']),
                $row['state'] === 'flagged' ? '✗ flagged' : $row['verdict'],
            ], $result['rows']),
        );

        foreach ($result['unreviewed'] as $dimension) {
            $this->warn("The variation set for [{$dimension}] has not been reviewed by a person (see its \"reviewed\" field).");
        }
    }

    private function buildBase(FairnessData $data): int
    {
        $size = max(1, (int) $this->option('size'));

        $cases = GoldenSet::default()->all()
            ->reject(fn (GoldenCase $case) => in_array($case->category, ['safety', 'injection'], true))
            ->reject(fn (GoldenCase $case) => str_contains($case->id, 'holdout'))
            ->values();

        $this->line("{$cases->count()} golden cases to start from; writing {$this->shortfall($size, $cases)} more from the help center.");

        $cases = $cases->merge($this->topUp($cases, $size))->take($size)->sortBy('id')->values();

        $path = base_path((string) config('fairness.base'));

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $cases->map(fn (GoldenCase $case) => $case->toJson())->implode("\n")."\n");

        $this->info("Wrote {$cases->count()} base cases to ".config('fairness.base').' ('.$cases->countBy('category')->map(fn (int $n, string $c) => "{$c} {$n}")->implode(', ').').');

        return self::SUCCESS;
    }

    private function shortfall(int $size, Collection $cases): int
    {
        return max(0, $size - $cases->count());
    }

    /** @return Collection<int, GoldenCase> */
    private function topUp(Collection $have, int $size): Collection
    {
        $needed = $this->shortfall($size, $have);

        if ($needed === 0) {
            return collect();
        }

        $writer = new QuestionWriter((string) ($this->option('writer') ?: config('fairness.writer_provider')));

        $made = collect();

        foreach ((new HelpCenterCases($writer))->generate($needed * 2) as $case) {
            $made->push($case);

            $this->line("  {$made->count()}: {$case->id}");

            if ($made->count() >= $needed) {
                break;
            }
        }

        return $made;
    }

    private function buildRewrites(FairnessData $data): int
    {
        $path = base_path((string) config('fairness.variations').'/rewrites.jsonl');

        $existing = $data->rewrites();

        $writer = new RewriteWriter((string) ($this->option('writer') ?: config('fairness.writer_provider')));

        $cases = $data->base()->reject(fn (GoldenCase $case) => isset($existing[$case->id]));

        if ($this->option('limit') !== null) {
            $cases = $cases->take(max(1, (int) $this->option('limit')));
        }

        File::ensureDirectoryExists(dirname($path));

        $done = 0;

        foreach ($cases as $case) {
            try {
                $row = ['id' => $case->id, 'writer' => $writer->provider(), 'reviewed' => false, 'rewrites' => $writer->rewrite($case->question)];
            } catch (Throwable $e) {
                $this->warn("{$case->id}: no rewrite ({$e->getMessage()})");

                continue;
            }

            File::append($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            $done++;

            $this->line("  {$done}/{$cases->count()} {$case->id}");
        }

        $this->info("Drafted rewrites for {$done} cases. A person reads them before the findings mean anything.");

        return self::SUCCESS;
    }
}
