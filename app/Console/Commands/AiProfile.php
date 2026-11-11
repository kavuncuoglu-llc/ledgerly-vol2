<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Hardware\HostProbe;
use App\Ai\Hardware\ShapeResolver;
use App\Ai\Profile\Bandwidth;
use App\Ai\Profile\MemorySampler;
use App\Ai\Profile\Profile;
use App\Ai\Profile\ProfileRow;
use App\Ai\Profile\ProfileRunner;
use App\Ai\Profile\ProfileSet;
use App\Ai\Profile\Residency;
use App\Ai\Profile\ShellMemorySampler;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

class AiProfile extends Command
{
    protected $signature = 'ai:profile
        {--provider=local : A provider name or tier (small, mid, frontier)}
        {--prompts=storage/samples/profile-set.json : The fixed prompt set}
        {--interval=1 : Seconds between memory samples}
        {--process=ollama,llama-server : Process names whose resident memory is the model server\'s}
        {--bandwidth= : Memory bandwidth in GB/s for the predicted decode rate, when this machine is not recognised}
        {--max-tokens=200 : Cap on each reply}';

    protected $description = 'Run a fixed prompt set against a provider while sampling memory and throughput every second (Chapter 21, Topic 9)';

    public function handle(AiManager $ai, HostProbe $host, ShapeResolver $resolver): int
    {
        $provider = (string) (config('ai.tiers')[$this->option('provider')] ?? $this->option('provider'));

        if (! $ai->configured($provider)) {
            $this->error("The '{$provider}' provider is not set up. `php artisan ai:providers` shows which are.");

            return self::FAILURE;
        }

        try {
            $prompts = ProfileSet::load($this->path((string) $this->option('prompts')));
            $profile = $this->runner($ai, $host)->run($provider, $prompts, (int) $this->option('max-tokens'));
        } catch (InvalidArgumentException|ProviderNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException|RequestException $e) {
            $this->error("The '{$provider}' provider did not answer: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->printTimeline($profile);
        $this->printSummary($profile, $provider, $ai->config($provider), $host, $resolver);

        return self::SUCCESS;
    }

    private function runner(AiManager $ai, HostProbe $host): ProfileRunner
    {
        $sampler = app()->bound(MemorySampler::class)
            ? app(MemorySampler::class)
            : new ShellMemorySampler($host, array_values(array_filter(array_map('trim', explode(',', (string) $this->option('process'))))), max(1, (int) $this->option('interval')));

        return new ProfileRunner($ai, $sampler);
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function printTimeline(Profile $profile): void
    {
        $this->line(sprintf('%-6s%-13s%-9s%-11s%-16s%-15s%s', 't(s)', 'resident GB', 'gpu GB', 'phase', 'prefill tok/s', 'decode tok/s', 'ctx tokens'));

        $rows = $profile->rows;

        if (count($rows) > 40) {
            $head = array_slice($rows, 0, 20);
            $tail = array_slice($rows, -10);

            foreach ($head as $row) {
                $this->line($this->format($row));
            }

            $this->line('...');

            foreach ($tail as $row) {
                $this->line($this->format($row));
            }

            return;
        }

        foreach ($rows as $row) {
            $this->line($this->format($row));
        }
    }

    private function format(ProfileRow $row): string
    {
        return sprintf(
            '%-6d%-13s%-9s%-11s%-16s%-15s%s',
            $row->second,
            $this->number($row->residentGb),
            $this->number($row->gpuGb),
            $row->phase,
            $this->number($row->prefillRate, 0),
            $this->number($row->decodeRate, 1),
            number_format($row->contextTokens),
        );
    }

    private function number(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($value, $decimals);
    }

    private function printSummary(Profile $profile, string $provider, array $config, HostProbe $host, ShapeResolver $resolver): void
    {
        $parts = [
            'load-to-first-token '.($profile->loadToFirstToken === null ? '—' : number_format($profile->loadToFirstToken, 1).' s'),
            'prefill '.($profile->prefillRate === null ? '—' : number_format($profile->prefillRate).' tok/s'),
            'decode '.($profile->decodeRate === null ? '—' : number_format($profile->decodeRate, 1).' tok/s').$this->prediction($profile, $config, $host, $resolver),
            'peak '.($profile->peakGb === null ? '—' : number_format($profile->peakGb, 1).' GB'),
            $this->spilled($config),
        ];

        $this->line('summary: '.implode(' · ', $parts));
    }

    // decode is bound by bandwidth (Chapter 3): every token reads every weight once, so tokens a second <= bandwidth / weights; real servers reach 80 to 120 percent of that
    private function prediction(Profile $profile, array $config, HostProbe $host, ShapeResolver $resolver): string
    {
        $bandwidth = $this->bandwidth($host);

        if ($bandwidth === null) {
            return ' (no bandwidth known: pass --bandwidth=GB/s for a predicted range)';
        }

        $weightsBytes = $resolver->resolve((string) $config['model'])->weightsBytes();

        if ($weightsBytes <= 0) {
            return '';
        }

        $ceiling = $bandwidth[1] * 1_000_000_000 / $weightsBytes;

        return sprintf(' (%s, ~%.0f GB/s: predicted %.0f–%.0f)', $bandwidth[0], $bandwidth[1], $ceiling * 0.8, $ceiling * 1.2);
    }

    private function bandwidth(HostProbe $host): ?array
    {
        if ($this->option('bandwidth') !== null) {
            return ['set by --bandwidth', (float) $this->option('bandwidth')];
        }

        return (new Bandwidth($host))->detect();
    }

    private function spilled(array $config): string
    {
        $spilled = Residency::spilledGb((string) $config['base_url'], (string) $config['model']);

        if ($spilled === null) {
            return 'spill unknown (only Ollama says)';
        }

        return $spilled < 0.05
            ? '0 spilled'
            : sprintf('%.1f GB spilled to CPU', $spilled);
    }
}
