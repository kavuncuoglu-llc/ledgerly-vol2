<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use Illuminate\Console\Command;

class AiProviders extends Command
{
    protected $signature = 'ai:providers';

    protected $description = 'List the named providers in config/ai.php: driver, model, endpoint and capabilities (Chapter 19)';

    private const CAPABILITY_LABELS = [
        'tools' => 'tools',
        'structured' => 'structured',
        'seed' => 'seed',
        'vision' => 'vision',
        'ctx_override' => 'ctx-override',
        'cache_breakpoints' => 'cache-breakpoints',
    ];

    public function handle(AiManager $ai): int
    {
        $rows = array_map(fn (array $provider) => $this->row($ai, $provider), $ai->providers());

        $this->table(['name', 'driver', 'model', 'base_url', 'capabilities', 'license (reviewed)'], $rows);

        $unset = array_keys(array_filter($ai->providers(), fn (array $provider) => ! $ai->configured($provider['name'])));

        if ($unset !== []) {
            $this->line('Not set up: '.implode(', ', $unset).'. Nothing is sent to a provider that is not set up.');
        }

        return self::SUCCESS;
    }

    private function row(AiManager $ai, array $provider): array
    {
        $configured = $ai->configured($provider['name']);

        return [
            $provider['name'],
            $provider['driver'] ?? '—',
            $provider['model'] ?? '—',
            $provider['base_url'] ?? '—',
            $configured ? $this->capabilities($provider) : '(not set)',
            $this->license($provider),
        ];
    }

    private function capabilities(array $provider): string
    {
        return match ($provider['driver'] ?? null) {
            'fake' => 'everything',
            'sidecar' => 'rerank',
            default => $this->listed($provider),
        };
    }

    private function listed(array $provider): string
    {
        $capabilities = $provider['capabilities'] ?? ['tools', 'structured', 'seed', 'ctx_override'];

        $labels = array_map(fn (string $capability) => self::CAPABILITY_LABELS[$capability] ?? $capability, $capabilities);

        if (isset($provider['dimensions'])) {
            $labels = ['embed ('.$provider['dimensions'].')'];
        }

        return implode(', ', $labels);
    }

    private function license(array $provider): string
    {
        if (empty($provider['license'])) {
            return '—';
        }

        return $provider['license'].' ('.($provider['license_reviewed'] ?? 'unreviewed').')';
    }
}
