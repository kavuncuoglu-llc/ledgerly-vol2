<?php

namespace App\Console\Commands;

use App\Mail\SpendShowback;
use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AiShowback extends Command
{
    protected $signature = 'ai:showback
        {--weeks=1 : How many weeks back}
        {--send : Mail each team its own lines (the teams\' addresses are in config ai.teams)}';

    protected $description = 'Spend by team and feature from the ledger, and optionally mail each team its share (Chapter 36; weekly)';

    public function handle(): int
    {
        $since = CarbonImmutable::now()->subWeeks(max(1, (int) $this->option('weeks')))->startOfDay();

        $rows = AiLedgerEntry::query()
            ->where('occurred_at', '>=', $since)
            ->select('team', 'feature', DB::raw('count(*) as calls'), DB::raw('sum(cost_micro_usd) as micros'))
            ->groupBy('team', 'feature')
            ->orderBy('team')
            ->orderByDesc('micros')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('No spend in the window.');

            return self::SUCCESS;
        }

        $this->table(['team', 'feature', 'calls', 'spend'], $rows->map(fn ($row) => [$row->team, $row->feature, number_format($row->calls), '$'.number_format($row->micros / 1_000_000, 2)])->all());

        $this->line('total $'.number_format($rows->sum('micros') / 1_000_000, 2));

        if ($this->option('send')) {
            $this->send($rows->groupBy('team'), $since);
        }

        return self::SUCCESS;
    }

    private function send($byTeam, CarbonImmutable $since): void
    {
        foreach ($byTeam as $team => $rows) {
            $addresses = array_values(array_filter((array) config("ai.teams.{$team}.email")));

            if ($addresses === []) {
                $this->warn("{$team}: no address in ai.teams.{$team}.email, not sent");

                continue;
            }

            Mail::to($addresses)->send(new SpendShowback((string) $team, $since->toDateString(), $rows->map(fn ($row) => ['feature' => $row->feature, 'calls' => (int) $row->calls, 'usd' => $row->micros / 1_000_000])->all(), $rows->sum('micros') / 1_000_000));

            $this->info("{$team}: mailed to ".implode(', ', $addresses));
        }
    }
}
