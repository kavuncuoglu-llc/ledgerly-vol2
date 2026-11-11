<?php

namespace App\Console\Commands;

use App\Ai\Ledger\CounterRebuilder;
use App\Ai\Ledger\FileProviderUsage;
use App\Ai\Ledger\HttpProviderUsage;
use App\Ai\Ledger\ProviderUsage;
use App\Ai\Ledger\Reconciler;
use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class AiReconcile extends Command
{
    protected $signature = 'ai:reconcile
        {--date= : The day to reconcile (default: yesterday)}
        {--usage= : A JSON usage export from the provider, instead of its usage endpoint (ai.budgets.reconcile.usage_file)}
        {--provider= : Only this provider (default: every hosted provider in the ledger that day)}
        {--no-rebuild : Do not rebuild the running period counters from the ledger}';

    protected $description = 'Compare the ledger with each provider\'s usage figures for a day, report the gap, and rebuild the budget counters from the ledger (Chapter 36; nightly)';

    public function handle(Reconciler $reconciler, CounterRebuilder $rebuilder): int
    {
        Artisan::call('ai:ledger-consume', ['--once' => true]);                       // what is still on the stream is part of yesterday's total

        $date = (string) ($this->option('date') ?: CarbonImmutable::yesterday()->toDateString());

        $failed = false;

        foreach ($this->providers($date) as $provider) {
            $failed = $this->reconcile($reconciler, $provider, $date) || $failed;
        }

        if (! $this->option('no-rebuild')) {
            $this->line(sprintf('rebuilt %d budget counters from the ledger', $rebuilder->rebuild()));
        }

        return $failed
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function reconcile(Reconciler $reconciler, string $provider, string $date): bool
    {
        try {
            $rows = $reconciler->compare($provider, $date, $this->source());
        } catch (Throwable $e) {
            $this->warn("{$provider}: not reconciled ({$e->getMessage()})");

            return false;
        }

        $this->line("{$provider} on {$date}");

        $this->table(
            ['model', 'ledger', 'provider', 'gap', 'tokens (ledger / provider)', 'verdict'],
            array_map(fn (array $row) => [
                $row['model'],
                '$'.number_format($row['ledger_usd'], 4),
                '$'.number_format($row['provider_usd'], 4),
                sprintf('%s$%s (%d%%)', $row['gap_usd'] < 0 ? '-' : '+', number_format(abs($row['gap_usd']), 4), round($row['gap_share'] * 100)),
                number_format($row['ledger_tokens']).' / '.number_format($row['provider_tokens']),
                $row['ok'] ? '✓ '.$row['diagnosis'] : '✗ '.$row['diagnosis'],
            ], $rows),
        );

        return collect($rows)->contains(fn (array $row) => ! $row['ok']);
    }

    private function source(): ProviderUsage
    {
        $file = $this->option('usage') ?: config('ai.budgets.reconcile.usage_file');

        return filled($file)
            ? new FileProviderUsage((string) $file)
            : new HttpProviderUsage;
    }

    /** @return string[] */
    private function providers(string $date): array
    {
        if ($this->option('provider')) {
            return [(string) $this->option('provider')];
        }

        return AiLedgerEntry::query()
            ->whereDate('occurred_at', $date)
            ->distinct()
            ->pluck('provider')
            ->filter(fn (string $provider) => (bool) config("ai.providers.{$provider}.hosted"))
            ->values()
            ->all();
    }
}
