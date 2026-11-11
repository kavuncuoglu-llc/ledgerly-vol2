<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Experiments\Candidate;
use App\Ai\Experiments\Experiment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiModelsAudit extends Command
{
    protected $signature = 'ai:models:audit {--warn-days=30 : Fail when a model retires within this many days and no replacement is named}';

    protected $description = 'Every role\'s model: what it is pinned to, when it is deprecated, and where its replacement is on the release ladder (Chapter 30, Topic 7)';

    public function handle(AiManager $ai): int
    {
        $rows = [];

        $urgent = [];

        foreach ($this->roles($ai) as $role => $name) {
            $config = $ai->providers()[$name] ?? [];

            $model = (string) ($config['model'] ?? '');

            $days = $this->daysLeft($config['deprecates_on'] ?? null);

            $candidate = (string) ($config['replacement_candidate'] ?? '');

            if ($days !== null && $days <= (int) $this->option('warn-days') && $candidate === '') {
                $urgent[] = "{$role} ({$model}) retires in {$days} days and has no replacement candidate";
            }

            $rows[] = [
                $role,
                $model === '' ? '(not set)' : $model,
                $this->pin($config),
                $config['deprecates_on'] ?? '—',
                $days ?? '—',
                $candidate === '' ? '—' : $candidate,
                $candidate === '' ? '—' : $this->stage($candidate),
            ];
        }

        $rows[] = $this->judgeRow();

        $this->table(['role', 'model', 'pinned to', 'deprecates', 'days left', 'candidate', 'ladder stage'], $rows);

        foreach ($urgent as $line) {
            $this->error($line);
        }

        return $urgent === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<string, string> role => provider name */
    private function roles(AiManager $ai): array
    {
        return ['local' => 'local', 'mid' => (string) config('ai.tiers.mid'), 'frontier' => 'frontier'];
    }

    private function pin(array $config): string
    {
        if (! ($config['pinned'] ?? false)) {
            return 'NOT PINNED';
        }

        $model = (string) ($config['model'] ?? '');

        if (preg_match('/(^|[-:])(latest|stable|default)$/', $model) === 1) {
            return 'ALIAS: '.$model;                                                     // an alias moves when the provider moves it
        }

        $digest = (string) ($config['digest'] ?? '');

        if (($config['native'] ?? null) !== 'ollama') {
            return 'fixed ID';
        }

        if ($digest === '') {
            return 'tag only (no digest recorded)';
        }

        $actual = $this->ollamaDigest($config, $model);

        return match (true) {
            $actual === null => 'sha256:'.substr(ltrim($digest, 'sha256:'), 0, 8).'… (server not reached)',
            ltrim($actual, 'sha256:') === ltrim($digest, 'sha256:') => 'sha256:'.substr(ltrim($digest, 'sha256:'), 0, 8).'… ✓',
            default => 'DIGEST CHANGED: config '.substr(ltrim($digest, 'sha256:'), 0, 8).'…, server '.substr(ltrim($actual, 'sha256:'), 0, 8).'…',
        };
    }

    private function ollamaDigest(array $config, string $model): ?string
    {
        try {
            $root = preg_replace('#/v1/?$#', '', (string) ($config['base_url'] ?? ''));

            $tags = Http::timeout(3)->get($root.'/api/tags')->json('models', []);

            return collect($tags)->firstWhere('name', $model)['digest'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function daysLeft(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        return (int) CarbonImmutable::now()->startOfDay()->diffInDays(CarbonImmutable::parse($date)->startOfDay(), false);
    }

    private function stage(string $candidate): string
    {
        foreach (Experiment::names() as $name) {
            $settings = Experiment::settings($name);

            if ($settings === null) {
                continue;
            }

            $matches = isset($settings['treatment']['candidate']) && $this->matches($settings['treatment']['candidate'], $candidate);

            if ($matches) {
                return $settings['mode'] === 'shadow' ? 'shadowing' : 'A/B testing';
            }
        }

        return 'named; offline evaluation not started';
    }

    private function matches(string $source, string $candidate): bool
    {
        try {
            $found = Candidate::from($source);
        } catch (Throwable) {
            return false;
        }

        return $found->name === $candidate
            || $found->provider === $candidate;
    }

    private function judgeRow(): array
    {
        $model = (string) config('ai.python.judge.model');

        return ['judge', $model, getenv('JUDGE_MODEL') !== false ? 'fixed ID' : 'default (set JUDGE_MODEL)', '—', '—', '—', '—'];
    }
}
