<?php

namespace App\Console\Commands;

use App\Ai\Budgets\SpendBreaker;
use Illuminate\Console\Command;

class AiSpendBreaker extends Command
{
    protected $signature = 'ai:spend-breaker';

    protected $description = 'Compare the last fifteen minutes of spend per feature and tenant with its normal for that hour and open, half-open or close the spend breakers (Chapter 36; scheduled every minute)';

    public function handle(SpendBreaker $breaker): int
    {
        $report = $breaker->evaluate();

        $interesting = array_filter($report, fn (array $row) => $row['state'] !== SpendBreaker::CLOSED || $row['changed'] || $row['current'] > 0);

        if ($interesting === []) {
            $this->line('No spend in the window.');

            return self::SUCCESS;
        }

        $this->table(
            ['scope', 'last window', 'normal', 'x normal', 'breaker'],
            array_map(fn (array $row) => [
                $row['scope'],
                '$'.number_format($row['current'] / 1_000_000, 2),
                '$'.number_format($row['baseline'] / 1_000_000, 2),
                $row['ratio'] === null ? '—' : number_format($row['ratio'], 1).'×',
                $row['state'].($row['changed'] ? ' (changed now)' : ''),
            ], array_values($interesting)),
        );

        return self::SUCCESS;
    }
}
