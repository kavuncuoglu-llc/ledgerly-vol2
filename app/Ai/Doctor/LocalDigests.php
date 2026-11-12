<?php

namespace App\Ai\Doctor;

use App\Ai\Registry\ServeFineTune;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 50, Topic 5: a local tag can be re-pointed at other weights, so the app records each local model's digest and `ai:doctor --local` compares it, against config for a base model and against the registry for a fine-tune.
final class LocalDigests
{
    public function __construct(private ServeFineTune $serve) {}

    /** @return list<LocalDigestRow> */
    public function rows(): array
    {
        return [...$this->configRows(), ...$this->registryRows()];
    }

    /** @return list<LocalDigestRow> */
    private function configRows(): array
    {
        $rows = [];

        foreach ((array) config('ai.providers') as $role => $config) {
            if (! is_array($config)) {
                continue;
            }

            if (($config['native'] ?? null) !== 'ollama') {
                continue;
            }

            if (! in_array($role, $this->baseRoles(), true)) {
                continue;
            }

            $rows[] = $this->configRow((string) $role, $config);
        }

        return $rows;
    }

    private function baseRoles(): array
    {
        $roles = ['local'];

        foreach (['local-70b', 'mid-in-region'] as $role) {
            if (filled(config("ai.providers.{$role}.digest"))) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    private function configRow(string $role, array $config): LocalDigestRow
    {
        $model = (string) ($config['model'] ?? '');

        $recorded = (string) ($config['digest'] ?? '');

        $actual = $this->reported((string) ($config['base_url'] ?? ''), $model);

        return match (true) {
            $actual === false => new LocalDigestRow($role, $model, null, LocalDigestRow::UNREACHABLE, 'server not reached'),
            $actual === null => new LocalDigestRow($role, $model, null, LocalDigestRow::MISSING, 'the server does not have this model'),
            $recorded === '' => new LocalDigestRow($role, $model, $actual, LocalDigestRow::UNRECORDED, 'no digest recorded in config (set the role\'s digest variable, such as AI_LOCAL_DIGEST, to this one)'),
            $this->same($recorded, $actual) => new LocalDigestRow($role, $model, $actual, LocalDigestRow::OK, 'matches config'),
            default => new LocalDigestRow($role, $model, $actual, LocalDigestRow::CHANGED, 'DIGEST CHANGED: config has sha256:'.substr($this->bare($recorded), 0, 4).'…, the server has sha256:'.substr($this->bare($actual), 0, 4).'…'),
        };
    }

    /** @return list<LocalDigestRow> */
    private function registryRows(): array
    {
        try {
            $entries = ModelRegistryEntry::query()->whereIn('stage', ['production', 'canary', 'shadow', 'previous'])->get();
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($entries as $entry) {
            foreach ((array) $entry->served_on as $server => $served) {
                if (isset($served['unloaded_at'])) {
                    continue;
                }

                $rows[] = $this->registryRow($entry, (string) $server, (string) ($served['digest'] ?? ''));
            }
        }

        return $rows;
    }

    private function registryRow(ModelRegistryEntry $entry, string $server, string $recorded): LocalDigestRow
    {
        $role = (string) (config('ai.registry.roles')[$entry->name] ?? $entry->name);

        try {
            $actual = $this->serve->digestFor($entry, $server);
        } catch (Throwable) {
            return new LocalDigestRow($role, $entry->model, null, LocalDigestRow::UNREACHABLE, "server [{$server}] not reached");
        }

        $label = "registry {$entry->name}@{$entry->stage}";

        return match (true) {
            $actual === null => new LocalDigestRow($role, $entry->model, null, LocalDigestRow::MISSING, "{$server} no longer has this model ({$label})"),
            $recorded === '' => new LocalDigestRow($role, $entry->model, $actual, LocalDigestRow::UNRECORDED, "no digest recorded when it was loaded ({$label})"),
            $this->same($recorded, $actual) => new LocalDigestRow($role, $entry->model, $actual, LocalDigestRow::OK, "matches {$label}"),
            default => new LocalDigestRow($role, $entry->model, $actual, LocalDigestRow::CHANGED, "DIGEST CHANGED since {$label} was loaded on {$server}"),
        };
    }

    // false: the server did not answer. null: it answered and does not have the model.
    private function reported(string $baseUrl, string $model): string|false|null
    {
        try {
            $root = preg_replace('#/v1/?$#', '', $baseUrl);

            $tags = Http::timeout(3)->get($root.'/api/tags')->throw()->json('models', []);
        } catch (Throwable) {
            return false;
        }

        foreach ((array) $tags as $tag) {
            if (in_array($tag['name'] ?? '', [$model, $model.':latest'], true)) {
                return 'sha256:'.$this->bare((string) ($tag['digest'] ?? ''));
            }
        }

        return null;
    }

    private function same(string $a, string $b): bool
    {
        return $this->bare($a) === $this->bare($b);
    }

    private function bare(string $digest): string
    {
        return str_replace('sha256:', '', $digest);
    }
}
