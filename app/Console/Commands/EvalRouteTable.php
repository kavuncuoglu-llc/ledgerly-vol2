<?php

namespace App\Console\Commands;

use App\Ai\Routing\ExpectedCost;
use App\Ai\Routing\RoleEvaluator;
use App\Ai\Routing\RouteTableBuilder;
use App\Ai\Routing\RouteTableWriter;
use App\Evals\GoldenSet;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class EvalRouteTable extends Command
{
    protected $signature = 'eval:route-table
        {--set=golden : The golden set (tests/golden)}
        {--roles= : Roles to measure, cheapest first (default ai.cascade.roles: local,mid,frontier)}
        {--tiers= : The same as --roles}
        {--sample=all : all, smoke, or a number of cases}
        {--margin=1 : Points over the floor a role needs}
        {--floor= : The scorecard floor in points (default ai.cascade.floor)}
        {--cascade= : Categories to cascade whatever their gap, comma separated}
        {--from= : Read pass rates from a JSON file ({"billing": {"local": 0.91, ...}}) instead of running the golden set}
        {--save= : Write the measured pass rates to this JSON file, to reuse with --from}
        {--out= : Write the routing table here (config/ai-routes.php)}
        {--write : Write config/ai-routes.php}';

    protected $description = 'Measure each role on the golden set and write the routing table: the cheapest role that clears the floor for each category (Chapter 37)';

    public function handle(RoleEvaluator $evaluator, GoldenSet $set, RouteTableBuilder $builder, RouteTableWriter $writer): int
    {
        if ($this->option('set') !== 'golden') {
            $this->error('Only the golden set exists: --set=golden.');

            return self::FAILURE;
        }

        $roles = $this->roles();

        try {
            [$rates, $skipped] = $this->option('from')
                ? [$this->fromFile((string) $this->option('from')), []]
                : $this->measured($evaluator, $set, $roles);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($skipped as $role => $why) {
            $this->warn("{$role}: not measured ({$why})");
        }

        if ($rates === []) {
            $this->error('No pass rates: no role could be measured. Set one up, or pass --from=rates.json.');

            return self::FAILURE;
        }

        if ($this->option('save')) {
            File::put($this->path((string) $this->option('save')), json_encode($rates, JSON_PRETTY_PRINT));
        }

        $floor = $this->option('floor') !== null
            ? (float) $this->option('floor') / 100
            : (float) config('ai.cascade.floor', 0.95);

        $margin = (float) $this->option('margin') / 100;

        $routes = $builder->build($rates, $roles, $floor, $margin, array_filter(array_map('trim', explode(',', (string) $this->option('cascade')))), (float) config('ai.cascade.cascade_gap_min'), (float) config('ai.cascade.cascade_gap_max'));

        $shares = $this->shares(array_keys($routes));

        $this->table(
            ['category (share of traffic)', ...$roles, 'floor', 'route'],
            collect($routes)->map(fn (array $route, string $category) => [
                sprintf('%s (%d%%)', $category, round(($shares[$category] ?? 0) * 100)),
                ...array_map(fn (string $role) => isset($route['rates'][$role]) ? round($route['rates'][$role] * 100).'%' : '—', $roles),
                round($floor * 100).'%',
                $route['cascade']
                    ? "{$route['start']} → cascade to ".$route['chain'][1]
                    : $route['start'],
            ])->values()->all(),
        );

        $previous = config('ai-routes', []);

        $changed = collect($routes)->filter(fn (array $route, string $category) => ($previous[$category]['default']['start'] ?? null) !== $route['start'] || ($previous[$category]['default']['cascade'] ?? false) !== $route['cascade'])->map(fn (array $route, string $category) => sprintf('%s (%s → %s%s)', $category, $previous[$category]['default']['start'] ?? 'nothing', $route['start'], $route['cascade'] ? ' with cascade' : ''))->values()->all();

        $out = $this->option('out') ?: ($this->option('write') ? 'config/ai-routes.php' : null);

        if ($out !== null) {
            File::put($this->path($out), $writer->render($routes, ['written_on' => now()->toDateString(), 'golden_cases' => $this->option('from') ? null : $set->all()->count(), 'floor' => $floor, 'margin' => $margin, 'roles' => $roles, 'source' => $this->option('from') ? 'a pass-rate file' : 'a golden-set run'], $this->residencyRoutes()));
        }

        $this->line(sprintf('  %s %d routes%s', $out === null ? 'would write' : "wrote {$out}:", count($routes), $changed === [] ? ' · no change' : ' · changed: '.implode(', ', $changed)));

        $this->line('  '.$this->projection($routes, $shares));

        return self::SUCCESS;
    }

    /** @return array{0: array, 1: array} */
    private function measured(RoleEvaluator $evaluator, GoldenSet $set, array $roles): array
    {
        $sample = (string) $this->option('sample');

        $cases = ctype_digit($sample)
            ? $set->smoke((int) $sample)
            : $set->subset($sample);

        if ($cases->isEmpty()) {
            throw new InvalidArgumentException('No golden cases found in tests/golden.');
        }

        $this->line($cases->count().' cases, roles '.implode(', ', $roles));

        $measured = $evaluator->measure($cases, $roles, false, fn (string $role, int $done, int $total) => $this->output->isVerbose() ? $this->line("  {$role} [{$done}/{$total}]") : null);

        return [$measured['rates'], $measured['skipped']];
    }

    private function fromFile(string $path): array
    {
        $file = $this->path($path);

        if (! is_file($file)) {
            throw new InvalidArgumentException("The pass-rate file [{$path}] does not exist.");
        }

        $rates = json_decode((string) file_get_contents($file), true);

        if (! is_array($rates)) {
            throw new InvalidArgumentException("The pass-rate file [{$path}] is not JSON.");
        }

        return $rates;
    }

    /** @return array<string, float> */
    private function shares(array $categories): array
    {
        $counts = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->whereNotNull('category')->where('created_at', '>=', now()->subDays(30))->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->all();

        $counts = collect($counts)->mapWithKeys(fn ($n, $category) => [(string) (config('ai.cascade.category_map')[$category] ?? $category) => (int) $n])->all();

        $total = array_sum($counts);

        if ($total === 0) {
            return array_fill_keys($categories, 1 / max(1, count($categories)));            // no traffic yet: every category counts the same
        }

        return array_map(fn (int $n) => $n / $total, $counts);
    }

    // What the table is worth: the expected hosted cost per answer (mid and frontier; local costs nothing at the margin) against sending every answer to the most capable role, at the traffic mix above.
    private function projection(array $routes, array $shares): string
    {
        $costs = (array) config('ai.cascade.cost_per_answer_usd');

        $roles = array_keys($costs);

        $top = (string) end($roles);

        $table = 0.0;

        $baseline = 0.0;

        foreach ($routes as $category => $route) {
            $share = $shares[$category] ?? 0;

            $table += $share * ExpectedCost::of($route['chain'], $route['rates'], $costs);

            $baseline += $share * ($costs[$top] ?? 0);
        }

        if ($baseline <= 0) {
            return 'no hosted price configured: nothing to project';
        }

        $change = (int) round((1 - $table / $baseline) * 100);

        return sprintf('projected hosted spend for support.reply: %s at this traffic mix (illustrative prices: $%s against $%s an answer)', $change === 0 ? 'no change' : ($change > 0 ? '−' : '+').abs($change).'%', number_format($table, 4), number_format($baseline, 4));
    }

    /** @return string[] */
    private function roles(): array
    {
        $given = $this->option('roles') ?: $this->option('tiers');

        return $given
            ? array_values(array_filter(array_map('trim', explode(',', (string) $given))))
            : (array) config('ai.cascade.roles', ['local', 'mid', 'frontier']);
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    // Chapter 44: the residency routes in the table being replaced, kept as written.
    private function residencyRoutes(): array
    {
        return collect(config('ai-routes', []))
            ->reject(fn ($entry, $category) => $category === '_meta')
            ->map(fn ($entry) => $entry['residency'] ?? null)
            ->filter()
            ->all();
    }
}
