<?php

namespace App\Console\Commands;

use App\Ai\Training\HostPolicy;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DatasetAllows extends Command
{
    protected $signature = 'dataset:allows
        {dataset : A dataset version (billing-v1) or the Hub copy of its non-customer parts (billing-synthetic@a41c09e)}
        {--provider= : The provider or host that would hold the data}
        {--region= : The region it would be in}';

    protected $description = 'Say whether a dataset\'s manifest allows a provider and region to hold it; exit 1 when it does not (Chapter 43)';

    public function handle(HostPolicy $policy): int
    {
        $provider = (string) $this->option('provider');

        if ($provider === '') {
            $this->error('Name the provider: --provider=<name>.');

            return self::FAILURE;
        }

        try {
            $refusals = $policy->sessionRefusals((string) $this->argument('dataset'), $provider, $this->option('region') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($refusals !== []) {
            foreach ($refusals as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }

        $this->line("manifest allows {$provider}".($this->option('region') ? ' in '.$this->option('region') : '')." for {$this->argument('dataset')}");

        return self::SUCCESS;
    }
}
