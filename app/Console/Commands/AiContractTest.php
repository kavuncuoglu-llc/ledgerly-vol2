<?php

namespace App\Console\Commands;

use App\Ai\Contract\ContractResult;
use App\Ai\Contract\ContractSuite;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiContractTest extends Command
{
    protected $signature = 'ai:contract-test
        {--driver=all : raw, prism, ai-sdk or all: the driver the suite runs against}
        {--wire= : ollama or openai-compatible (default: every wire the driver speaks)}
        {--json : Print the results as JSON}';

    protected $description = 'Run the one suite every ChatModel driver must pass, with the HTTP faked: no server, no key, no network (Chapter 47)';

    public function handle(ContractSuite $suite): int
    {
        $drivers = $this->option('driver') === 'all'
            ? ContractSuite::DRIVERS
            : [(string) $this->option('driver')];

        $results = [];

        try {
            foreach ($drivers as $driver) {
                array_push($results, ...$suite->run($driver, $this->option('wire') ?: null));
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = array_filter($results, fn (ContractResult $result) => ! $result->passed);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (ContractResult $result) => $result->toArray(), $results), JSON_PRETTY_PRINT));

            return $failed === []
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->print($results);

        return $failed === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @param list<ContractResult> $results */
    private function print(array $results): void
    {
        foreach (collect($results)->groupBy(fn (ContractResult $r) => "{$r->driver} · {$r->wire}") as $heading => $group) {
            $this->line("<options=bold>{$heading}</>");

            foreach ($group as $result) {
                $mark = match (true) {
                    $result->gap => '<comment>gap </comment>',
                    $result->passed => '<info>pass</info>',
                    default => '<error>FAIL</error>',
                };

                $this->line("  {$mark}  {$result->check}".($result->detail === '' ? '' : " · {$result->detail}"));
            }
        }

        $passed = count(array_filter($results, fn (ContractResult $r) => $r->passed && ! $r->gap));
        $gaps = count(array_filter($results, fn (ContractResult $r) => $r->gap));
        $failed = count(array_filter($results, fn (ContractResult $r) => ! $r->passed));

        $this->newLine();

        $this->line("{$passed} passed, {$gaps} known gap".($gaps === 1 ? '' : 's').", {$failed} failed");
    }
}
