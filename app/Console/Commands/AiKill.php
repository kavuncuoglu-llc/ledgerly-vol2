<?php

namespace App\Console\Commands;

use App\Ai\Budgets\KillSwitch;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiKill extends Command
{
    protected $signature = 'ai:kill
        {--provider= : Stop all spend on this provider role}
        {--feature= : Stop all spend by this feature}
        {--app : Stop all AI spend in the whole app}
        {--reset : Turn the switch off again}
        {--list : Show the switches that are on}
        {--reason= : Why (required): it goes in the page, the log and the reset}';

    protected $description = 'Fire or reset the kill switch: an immediate stop of all spend on a provider, a feature or the app (Chapter 36)';

    public function handle(KillSwitch $kill): int
    {
        if ($this->option('list')) {
            return $this->show($kill);
        }

        $scope = $this->scope();

        if ($scope === null) {
            $this->error('Name one scope: --provider=NAME, --feature=NAME or --app.');

            return self::FAILURE;
        }

        if (blank($this->option('reason'))) {
            $this->error('Give a reason with --reason="...": a kill switch is never fired or reset without one.');

            return self::FAILURE;
        }

        try {
            return $this->option('reset')
                ? $this->reset($kill, $scope)
                : $this->fire($kill, $scope);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function fire(KillSwitch $kill, string $scope): int
    {
        $kill->activate($scope, (string) $this->option('reason'));

        $this->warn("Kill switch ON for [{$scope}]. Customers see the bottom rung (search results and a link to a person). It stays on until a person runs: php artisan ai:kill --reset ... --reason=\"...\"");

        return self::SUCCESS;
    }

    private function reset(KillSwitch $kill, string $scope): int
    {
        if (! $kill->reset($scope, (string) $this->option('reason'))) {
            $this->line("The kill switch [{$scope}] was not on.");

            return self::SUCCESS;
        }

        $this->info("Kill switch OFF for [{$scope}].");

        return self::SUCCESS;
    }

    private function show(KillSwitch $kill): int
    {
        $active = $kill->active();

        if ($active === []) {
            $this->line('No kill switch is on.');

            return self::SUCCESS;
        }

        $this->table(['scope', 'since', 'reason'], collect($active)->map(fn (array $row, string $scope) => [$scope, $row['at'], $row['reason']])->values()->all());

        return self::SUCCESS;
    }

    private function scope(): ?string
    {
        return match (true) {
            (bool) $this->option('app') => 'global',
            filled($this->option('provider')) => 'provider:'.$this->option('provider'),
            filled($this->option('feature')) => 'feature:'.$this->option('feature'),
            default => null,
        };
    }
}
