<?php

namespace App\Console\Commands;

use App\Ai\Cost\Audit\AuditCall;
use App\Ai\Cost\Audit\AuditReport;
use App\Ai\Cost\Audit\CostAudit;
use App\Ai\Cost\Audit\SignatureResult;
use App\Ai\Cost\Audit\Unit;
use App\Ai\Cost\UnpricedModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AiCostAudit extends Command
{
    protected $signature = 'ai:cost-audit
        {--since=7d : The window: 12h, 7d, 4w, or a date}
        {--feature= : Only this feature}
        {--by= : Also print cost per successful action by feature, tenant, version or route}
        {--conversation= : Per-turn input, cached share and cost of one conversation (instead of the report)}
        {--json : Print the report as JSON}
        {--strict : Exit 1 when any signature is flagged}';

    protected $description = 'Price every model call from its logged usage and report cost per successful action, the toxic tail and the five signatures of Chapter 35';

    public function handle(CostAudit $audit): int
    {
        try {
            $since = $this->since();

            $report = $audit->run($since, $this->option('feature') ?: null);
        } catch (UnpricedModel $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('conversation') !== null) {
            return $this->conversation($report, (int) $this->option('conversation'));
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        if ($this->option('strict') && $report->flagged() !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function render(AuditReport $report): void
    {
        $this->line($this->headline($report));

        $this->line(sprintf(
            'toxic tail      top 1%% of conversations = %d%% of spend, %d%% resolved',
            round($report->tailSpendShare * 100),
            round($report->tailResolvedShare * 100),
        ));

        $this->line('signatures');

        foreach ($report->signatures as $result) {
            $this->signature($result);
        }

        $next = array_filter(array_map(fn (SignatureResult $result) => $result->next, $report->flagged()));

        if ($next !== []) {
            $this->line('next            '.implode('; ', $next));
        }

        if ($this->option('by')) {
            $this->by($report, (string) $this->option('by'));
        }
    }

    private function headline(AuditReport $report): string
    {
        $parts = [sprintf('spend $%s', number_format($report->spend, 2))];

        foreach ($report->features as $feature => $row) {
            $parts[] = $row['per_success'] === null
                ? "no {$row['noun']} yet ({$feature})"
                : sprintf('$%s per %s (%s)', $this->money($row['per_success']), $row['noun'], $feature);
        }

        return implode(' · ', $parts);
    }

    private function signature(SignatureResult $result): void
    {
        $this->line(sprintf('  %s %-10s %s', $result->flagged ? '✗' : '✓', $result->name, $result->summary));

        foreach ($result->examples as $example) {
            $this->line('                  e.g. '.$example);
        }
    }

    private function by(AuditReport $report, string $dimension): void
    {
        $units = $report->data->units->filter(fn (Unit $unit) => $unit->conversationId !== null);

        $key = match ($dimension) {
            'feature' => fn (Unit $unit) => $unit->feature(),
            'tenant' => fn (Unit $unit) => $unit->tenantId === null ? 'anonymous' : 'tenant '.$unit->tenantId,
            'version' => fn (Unit $unit) => $unit->promptVersion(),
            'route' => fn (Unit $unit) => $unit->route(),
            default => throw new InvalidArgumentException('--by is feature, tenant, version or route.'),
        };

        $rows = $units->groupBy($key)->map(function ($group, $name) {
            $spend = $group->sum(fn (Unit $unit) => $unit->usd());

            $resolved = $group->where('resolved', true)->count();

            return [$name, $group->count(), $resolved, '$'.number_format($spend, 2), $resolved > 0 ? '$'.$this->money($spend / $resolved) : '—'];
        })->sortByDesc(fn (array $row) => (float) str_replace(['$', ','], '', $row[3]))->values()->all();

        $this->newLine();

        $this->table([$dimension, 'conversations', 'resolved', 'spend', 'per resolution'], $rows);
    }

    private function conversation(AuditReport $report, int $id): int
    {
        $calls = $report->data->calls->filter(fn (AuditCall $call) => $call->conversationId === $id)->values();

        if ($calls->isEmpty()) {
            $this->error("No priced calls for conversation {$id} in the window.");

            return self::FAILURE;
        }

        $this->table(
            ['turn', 'feature', 'model', 'input', 'cached', 'cached share', 'output', 'cost'],
            $calls->map(fn (AuditCall $call, int $index) => [
                $index + 1,
                $call->feature,
                $call->model,
                number_format($call->inputTokens),
                number_format($call->cachedInputTokens),
                $call->inputTokens > 0 ? round($call->cachedInputTokens / $call->inputTokens * 100).'%' : '—',
                number_format($call->outputTokens),
                '$'.number_format($call->usd, 5),
            ])->all(),
        );

        $first = $calls->first();

        $last = $calls->last();

        $this->line(sprintf('turn %d sent %s input tokens against %s on turn 1; %d%% of it from the prompt cache; the conversation cost $%s.', $calls->count(), number_format($last->inputTokens), number_format($first->inputTokens), $last->inputTokens > 0 ? round($last->cachedInputTokens / $last->inputTokens * 100) : 0, $this->money($calls->sum('usd'))));

        return self::SUCCESS;
    }

    private function since(): CarbonImmutable
    {
        $value = (string) $this->option('since');

        if (preg_match('/^(\d+)([hdw])$/', $value, $match) === 1) {
            $amount = (int) $match[1];

            return match ($match[2]) {
                'h' => CarbonImmutable::now()->subHours($amount),
                'd' => CarbonImmutable::now()->subDays($amount),
                'w' => CarbonImmutable::now()->subWeeks($amount),
            };
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException("--since is 12h, 7d, 4w or a date, not [{$value}].");
        }
    }

    private function money(float $usd): string
    {
        return $usd >= 0.1
            ? number_format($usd, 2)
            : rtrim(rtrim(number_format($usd, 4), '0'), '.');
    }
}
