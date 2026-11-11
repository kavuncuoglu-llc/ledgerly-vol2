<?php

namespace App\Console\Commands;

use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Budgets\SpendBreaker;
use App\Ai\Python\Streams;
use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AiSpend extends Command
{
    private const DIMENSIONS = ['tenant' => 'tenant_id', 'user' => 'user_id', 'feature' => 'feature', 'team' => 'team', 'provider' => 'provider', 'model' => 'model', 'kind' => 'kind'];

    protected $signature = 'ai:spend
        {--by=feature : Labels to split by, comma separated: tenant, user, feature, team, provider, model, kind}
        {--since=1h : The window: 30m, 1h, 7d}';

    protected $description = 'Live spend by any label, with the breaker state and the app hour ceiling (Chapter 36)';

    public function handle(BudgetGuard $guard, Counters $counters, SpendBreaker $breaker, KillSwitch $kill, Streams $streams): int
    {
        try {
            [$since, $minutes] = $this->window();

            $by = $this->dimensions();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $this->rows($since, $by);

        $states = $breaker->states();

        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                ...array_map(fn (string $dimension) => $this->label($dimension, $row->{self::DIMENSIONS[$dimension]}), $by),
                number_format($row->calls),
                $this->compact((int) $row->input).' / '.$this->compact((int) $row->output),
                '$'.$this->money($row->micros / 1_000_000),
                $this->versusNormal($row, $by, $breaker, $minutes, $states),
            ];
        }

        $this->table([...$by, 'calls', 'tokens in / out', 'spend', 'vs. normal for this hour'], $table);

        $total = (int) collect($rows)->sum('micros');

        $hour = $guard->appHour();

        $this->line(sprintf('total          %s calls · $%s · app hourly ceiling $%s (%d%%)', number_format((int) collect($rows)->sum('calls')), number_format($total / 1_000_000, 2), number_format($hour->limitMicros / 1_000_000, 0), $hour->limitMicros > 0 ? round($counters->value($hour->key) / $hour->limitMicros * 100) : 0));

        foreach ($kill->active() as $scope => $row) {
            $this->warn("kill switch ON for {$scope} since {$row['at']}: {$row['reason']}");
        }

        $this->line(sprintf('ledger stream  %d entries, %d read and not yet written (ai:ledger-consume writes them in batches)', $streams->length((string) config('ai.ledger.stream')), $streams->pending((string) config('ai.ledger.stream'), (string) config('ai.ledger.group'))));

        return self::SUCCESS;
    }

    private function rows(CarbonImmutable $since, array $by)
    {
        $columns = array_map(fn (string $dimension) => self::DIMENSIONS[$dimension], $by);

        return AiLedgerEntry::query()
            ->where('occurred_at', '>=', $since)
            ->select([...$columns, DB::raw('count(*) as calls'), DB::raw('sum(input_tokens) as input'), DB::raw('sum(output_tokens) as output'), DB::raw('sum(cost_micro_usd) as micros')])
            ->groupBy($columns)
            ->orderByDesc('micros')
            ->get();
    }

    private function versusNormal(object $row, array $by, SpendBreaker $breaker, int $minutes, array $states): string
    {
        $scope = match (true) {
            in_array('feature', $by, true) => 'feature:'.$row->feature,
            in_array('tenant', $by, true) && $row->tenant_id !== null => 'tenant:'.$row->tenant_id,
            default => null,
        };

        if ($scope === null) {
            return '—';
        }

        $baseline = $breaker->baseline($scope, CarbonImmutable::now(), $minutes);

        $ratio = $baseline > 0
            ? number_format($row->micros / $baseline, 1).'×'
            : ($row->micros > 0 ? 'new' : '—');

        $state = $states[$scope] ?? null;

        return $state === null
            ? $ratio
            : sprintf('%s  ← breaker %s since %s', $ratio, strtoupper($state['state']), CarbonImmutable::parse($state['opened_at'])->format('H:i'));
    }

    private function label(string $dimension, mixed $value): string
    {
        if ($value === null) {
            return $dimension === 'tenant' ? 'anonymous' : '—';
        }

        return (string) $value;
    }

    private function money(float $usd): string
    {
        return number_format($usd, $usd > 0 && $usd < 0.1 ? 4 : 2);
    }

    private function compact(int $tokens): string
    {
        return match (true) {
            $tokens >= 1_000_000 => number_format($tokens / 1_000_000, 2).'M',
            $tokens >= 1_000 => number_format($tokens / 1_000).'k',
            default => (string) $tokens,
        };
    }

    /** @return array{0: CarbonImmutable, 1: int} */
    private function window(): array
    {
        if (preg_match('/^(\d+)([mhd])$/', (string) $this->option('since'), $match) !== 1) {
            throw new InvalidArgumentException('--since is 30m, 1h or 7d.');
        }

        $minutes = (int) $match[1] * match ($match[2]) {
            'm' => 1,
            'h' => 60,
            default => 1440,
        };

        return [CarbonImmutable::now()->subMinutes($minutes), $minutes];
    }

    /** @return string[] */
    private function dimensions(): array
    {
        $by = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('by')))));

        foreach ($by as $dimension) {
            if (! isset(self::DIMENSIONS[$dimension])) {
                throw new InvalidArgumentException('--by takes: '.implode(', ', array_keys(self::DIMENSIONS)).'.');
            }
        }

        return $by === []
            ? ['feature']
            : $by;
    }
}
