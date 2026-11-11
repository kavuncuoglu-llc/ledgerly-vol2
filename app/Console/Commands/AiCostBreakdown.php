<?php

namespace App\Console\Commands;

use App\Ai\Cost\ComponentBreakdown;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AiCostBreakdown extends Command
{
    protected $signature = 'ai:cost-breakdown
        {--feature=support.reply : The feature}
        {--since=7d : The window: 12h, 7d, 4w}
        {--as= : Price every call as this model (for example illustrative-frontier), to see what local calls would cost on a hosted role}';

    protected $description = 'Split a feature\'s cost by prompt section and output, each at the price that applied to it (Chapter 37, Figure 37-1)';

    public function handle(ComponentBreakdown $breakdown): int
    {
        if (preg_match('/^(\d+)([hdw])$/', (string) $this->option('since'), $match) !== 1) {
            $this->error('--since is 12h, 7d or 4w.');

            return self::FAILURE;
        }

        $since = CarbonImmutable::now()->sub(match ($match[2]) {
            'h' => $match[1].' hours',
            'w' => ((int) $match[1] * 7).' days',
            default => $match[1].' days',
        });

        $result = $breakdown->of((string) $this->option('feature'), $since, $this->option('as') ?: null);

        if ($result['calls'] === 0) {
            $this->line('No priced calls with a recorded context breakdown in the window.');

            return self::SUCCESS;
        }

        $total = max(1, $result['total_micros']);

        $this->table(
            ['component', 'tokens', 'priced as', 'micro-dollars', 'share'],
            [...array_map(fn (array $row) => [$row['component'], number_format($row['tokens']), $row['priced_as'], number_format($row['micros']), round($row['micros'] / $total * 100).'%'], $result['rows']), ['Total over '.number_format($result['calls']).' calls', number_format($result['total_tokens']), '', number_format($result['total_micros']), '100%']],
        );

        $this->line(sprintf('per call: %s tokens, %s micro-dollars (about $%s)', number_format($result['total_tokens'] / $result['calls']), number_format($result['total_micros'] / $result['calls']), number_format($result['total_micros'] / $result['calls'] / 1_000_000, 4)));

        if ($result['total_micros'] === 0) {
            $this->line('Every call in the window was free (your own hardware): add --as=illustrative-frontier to see what it would cost on a hosted role.');
        }

        return self::SUCCESS;
    }
}
