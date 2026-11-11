<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Compare\CallReplay;
use App\Ai\Compare\CompareResult;
use App\Ai\Compare\CompareRunner;
use App\Ai\Compare\CostModel;
use App\Ai\Compare\GoldenSet;
use App\Ai\Compare\Scorers;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\Retriever;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class AiCompare extends Command
{
    protected $signature = 'ai:compare
        {--golden=storage/golden/support.jsonl : A JSON-lines golden set (id, feature, category, type, input, expected)}
        {--providers=local,mid,frontier : Provider names or tiers (small, mid, frontier)}
        {--judge= : A provider that grades free-text replies 1 to 5 (a first judge; Chapter 30 builds the real one)}
        {--feature= : Only cases for this feature, for example ticket.classify}
        {--limit= : Only the first N cases}
        {--stream : Stream, so time to first token is measured}
        {--from-calls : Replay requests recorded from real traffic (ai.debug.record_requests) instead of a golden set}
        {--since=24h : With --from-calls, how far back to look (for example 6h, 3d)}
        {--no-save : Do not write storage/benchmarks/compare-DATE.json}';

    protected $description = 'Run one golden set against several providers: quality, tokens, latency, cost and quality per dollar, by feature and category (Chapter 20)';

    public function handle(AiManager $ai, Retriever $retriever, CitationChecker $citations): int
    {
        [$providers, $skipped] = $this->providers($ai);

        foreach ($skipped as $line) {
            $this->warn($line);
        }

        if ($providers === []) {
            $this->error('No provider to compare is set up. `php artisan ai:providers` shows which are.');

            return self::FAILURE;
        }

        $judge = $this->option('judge') ?: null;

        if ($judge !== null && ! $ai->configured($judge)) {
            $this->error("The judge '{$judge}' is not set up.");

            return self::FAILURE;
        }

        try {
            $cases = $this->cases($ai, $providers, $judge);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($cases === []) {
            $this->error('There are no cases to run.');

            return self::FAILURE;
        }

        $runner = new CompareRunner($ai, $retriever, new Scorers($ai, $citations, $judge), new CostModel);

        $this->line(sprintf('%d cases × %d providers (%s)%s', count($cases), count($providers), implode(', ', $providers), $judge ? ", judge: {$judge}" : ''));

        $result = $runner->run($cases, $providers, (bool) $this->option('stream'));

        $this->printTable($result, $ai);
        $this->printCategories($result);
        $this->printErrors($result);

        if (! $this->option('no-save')) {
            $this->save($result);
        }

        return self::SUCCESS;
    }

    /** @return array{0: list<string>, 1: list<string>} the providers to run, and a line for each one left out */
    private function providers(AiManager $ai): array
    {
        $providers = [];
        $skipped = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('providers')))) as $name) {
            $provider = (string) (config('ai.tiers')[$name] ?? $name);

            if (in_array($provider, $providers, true)) {
                continue;
            }

            if (! $ai->configured($provider)) {
                $skipped[] = "Skipped {$name}: the '{$provider}' provider is not set up.";

                continue;
            }

            $providers[] = $provider;
        }

        return [$providers, $skipped];
    }

    private function cases(AiManager $ai, array $providers, ?string $judge): array
    {
        $feature = $this->option('feature') ?: null;

        if ($this->option('from-calls')) {
            $this->refuseHosted($providers, $judge);

            return CallReplay::cases($feature, $this->since());
        }

        $path = (string) $this->option('golden');

        $limit = $this->option('limit') === null
            ? null
            : (int) $this->option('limit');

        return GoldenSet::only(GoldenSet::load(str_starts_with($path, '/') ? $path : base_path($path)), $feature, $limit);
    }

    private function refuseHosted(array $providers, ?string $judge): void
    {
        $hosted = array_values(array_filter([...$providers, $judge], fn (?string $name) => $name !== null && (config('ai.providers')[$name]['hosted'] ?? false)));

        if ($hosted !== []) {
            throw new InvalidArgumentException('--from-calls replays real customer requests, and '.implode(', ', $hosted).' is a hosted provider: that would send customer text off your network. Compare local providers only, or use a golden set.');
        }
    }

    private function since(): CarbonImmutable
    {
        if (preg_match('/^(\d+)([hd])$/', (string) $this->option('since'), $match) !== 1) {
            throw new InvalidArgumentException('--since takes a number and h or d, for example 6h or 3d.');
        }

        return $match[2] === 'h'
            ? CarbonImmutable::now()->subHours((int) $match[1])
            : CarbonImmutable::now()->subDays((int) $match[1]);
    }

    private function printTable(CompareResult $result, AiManager $ai): void
    {
        $this->newLine();
        $this->line(sprintf('%-17s %-12s %7s %8s %8s %8s %8s %14s %14s', 'feature', 'provider', 'quality', 'in tok', 'out tok', 'p50 ms', 'ttft ms', '$/1K requests', 'quality per $'));

        $unpriced = false;

        foreach ($result->rows() as $row) {
            $free = $row['usd_per_1k'] < 0.005;

            $unpriced = $unpriced || $free;

            $this->line(sprintf(
                '%-17s %-12s %7s %8s %8s %8s %8s %14s %14s',
                $row['feature'],
                $row['provider'],
                $row['quality'] === null ? '—' : number_format($row['quality'], 2),
                number_format($row['in']),
                number_format($row['out']),
                number_format($row['p50_ms']),
                $row['p50_ttft_ms'] === null ? '—' : number_format($row['p50_ttft_ms']),
                '$'.number_format($row['usd_per_1k'], 2).($free ? '*' : ''),
                $free ? '∞' : ($row['quality_per_dollar'] === null ? '—' : number_format($row['quality_per_dollar'], 2)),
            ));
        }

        if ($unpriced) {
            $this->line('* $0.00 means "not priced here": your own hardware is free until you set AI_GPU_USD_PER_HOUR (GPU-hour ÷ requests served at once), and a hosted provider needs its FRONTIER_RATE_IN / FRONTIER_RATE_OUT. The figures in .env.example are examples, not quotes.');
        }
    }

    private function printCategories(CompareResult $result): void
    {
        foreach ($result->byCategory() as $feature => $byProvider) {
            $categories = collect($byProvider)->flatMap(fn (array $scores) => array_keys($scores))->unique()->sort()->values();

            if ($categories->count() < 2) {
                continue;
            }

            $this->newLine();
            $this->line("  by category (quality), {$feature}:");
            $this->line('    '.str_pad('', 12).$categories->map(fn (string $category) => str_pad($category, 9))->implode(''));

            foreach ($byProvider as $provider => $scores) {
                $this->line('    '.str_pad($provider, 12).$categories->map(fn (string $category) => str_pad(isset($scores[$category]) ? number_format($scores[$category], 2) : '—', 9))->implode(''));
            }
        }
    }

    private function printErrors(CompareResult $result): void
    {
        $errors = $result->errors();

        if ($errors === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($errors).' request(s) failed and are left out of the averages. First: '.$errors[0]['provider'].' on '.$errors[0]['case'].': '.$errors[0]['error']);
    }

    private function save(CompareResult $result): void
    {
        $path = base_path('storage/benchmarks/compare-'.now()->toDateString().'.json');

        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode(['at' => now()->toIso8601String(), 'golden' => $this->option('from-calls') ? 'model_calls' : $this->option('golden')] + $result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->newLine();
        $this->line('wrote '.str_replace(base_path().'/', '', $path));
    }
}
