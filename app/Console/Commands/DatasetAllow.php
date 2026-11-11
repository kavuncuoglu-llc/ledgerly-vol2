<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use App\Ai\Training\HostPolicy;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DatasetAllow extends Command
{
    protected $signature = 'dataset:allow
        {dataset : The dataset version}
        {--provider= : The provider or host the customer-derived rows may go to}
        {--region= : The region the host must be in}
        {--reason= : Why: the agreement and terms that allow it}';

    protected $description = 'Record in a dataset\'s manifest that a provider (and region) may hold its customer-derived rows; ai:ft:prepare and the rented-GPU session check it (Chapter 41)';

    public function handle(HostPolicy $policy): int
    {
        $provider = (string) $this->option('provider');

        $reason = trim((string) $this->option('reason'));

        if ($provider === '') {
            $this->error('Name the provider: --provider=<name>.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('Say why: --reason="DPA signed 2026-10-01; section 4 of the tenants\' terms allows it".');

            return self::FAILURE;
        }

        try {
            $hosts = $policy->allow(Dataset::named((string) $this->argument('dataset')), $provider, $this->option('region') ?: null, $reason);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('  '.count($hosts).' allowance(s) recorded in manifest.json of '.$this->argument('dataset').': '.implode(', ', array_map(fn (array $host) => $host['provider'].($host['region'] === null ? '' : " ({$host['region']})"), $hosts)));

        return self::SUCCESS;
    }
}
