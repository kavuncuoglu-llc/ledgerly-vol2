<?php

namespace App\Ai\Licenses;

use App\Ai\Deprecations\RoutedModels;
use App\Models\ModelRegistryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

// Chapter 51, Topic 6: a license is a set of obligations you keep meeting, and they extend to every model derived from it. This reads what is served (the providers the app routes to, and every registered version that is not retired)
// and says, for each, whether a reviewed license is on record and whether each obligation has a place where it is met.
final class LicenseCheck
{
    public const USES = ['hosted-service', 'distributed-weights'];

    public function __construct(private RoutedModels $routed) {}

    /** @return list<array{served: string, license: string, ok: bool, why: string}> */
    public function rows(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();

        $rows = [];

        foreach ($this->routed->routedProviders() as $provider) {
            $config = $this->provider($provider);

            $rows[] = $this->providerRow($provider, $config, $today);
        }

        foreach ($this->registryEntries() as $entry) {
            $rows[] = $this->registryRow($entry, $today);
        }

        return $rows;
    }

    /** @return list<string> */
    public function failures(?CarbonImmutable $today = null): array
    {
        return array_values(array_map(
            fn (array $row) => "{$row['served']}: {$row['why']}",
            array_filter($this->rows($today), fn (array $row) => ! $row['ok']),
        ));
    }

    /** @return list<string> the attribution lines the public summary has to carry, for the licenses of what is served */
    public function attributions(): array
    {
        $names = [];

        foreach ($this->routed->routedProviders() as $provider) {
            $names[] = (string) ($this->provider($provider)['license'] ?? '');
        }

        foreach ($this->registryEntries() as $entry) {
            $names[] = (string) (config('ai-licenses.bases')[$entry->base] ?? '');
        }

        $lines = [];

        foreach (array_unique(array_filter($names)) as $name) {
            $text = (config('ai-licenses.licenses')[$name] ?? [])['attribution_text'] ?? null;            // a license name has dots in it: never a dotted config path

            if (filled($text)) {
                $lines[$text] = $text;
            }
        }

        return array_values($lines);
    }

    private function providerRow(string $provider, array $config, CarbonImmutable $today): array
    {
        $name = (string) ($config['license'] ?? '');

        $model = (string) ($config['model'] ?? $provider);

        $served = "{$provider} ({$model})";

        if ($name === '') {
            return ['served' => $served, 'license' => '', 'ok' => false, 'why' => 'served without a recorded license: set `license` on the provider in config/ai.php'];
        }

        if (in_array($name, (array) config('ai-licenses.hosted_terms'), true)) {
            return ['served' => $served, 'license' => $name, 'ok' => true, 'why' => 'hosted under the provider\'s own terms (the data map, Chapter 34, covers them)'];
        }

        return ['served' => $served, 'license' => $name] + $this->record($name, $today);
    }

    private function registryRow(ModelRegistryEntry $entry, CarbonImmutable $today): array
    {
        $served = "registry {$entry->name}@{$entry->stage} ({$entry->model}, v{$entry->version})";

        $base = (string) $entry->base;

        $name = config('ai-licenses.bases')[$base] ?? null;

        if ($name === null) {
            return ['served' => $served, 'license' => '', 'ok' => false, 'why' => "its base [{$base}] has no license in config/ai-licenses.php `bases`, so no obligation is carried over"];
        }

        $record = $this->record($name, $today);

        if (! $record['ok']) {
            return ['served' => $served, 'license' => $name, 'ok' => false, 'why' => "inherits {$name}, which is not clean: {$record['why']}"];
        }

        $missing = $this->obligationsMissingFromDerived($name);

        return $missing === []
            ? ['served' => $served, 'license' => $name, 'ok' => true, 'why' => "carries its base's license ({$name}) and every obligation of it"]
            : ['served' => $served, 'license' => $name, 'ok' => false, 'why' => 'does not carry its base\'s obligations: '.implode(', ', $missing)];
    }

    private function obligationsMissingFromDerived(string $license): array
    {
        $own = (array) ((config('ai-licenses.licenses')[$license] ?? [])['obligations'] ?? []);

        $missing = [];

        foreach (array_keys($own) as $obligation) {
            if (blank($own[$obligation] ?? null)) {
                $missing[] = $obligation;
            }
        }

        return $missing;
    }

    /** @return array{ok: bool, why: string} */
    private function record(string $name, CarbonImmutable $today): array
    {
        $record = config('ai-licenses.licenses')[$name] ?? null;

        if (! is_array($record)) {
            return ['ok' => false, 'why' => "the license [{$name}] has no reviewed record in config/ai-licenses.php"];
        }

        foreach (['text_version', 'reviewed', 'by', 'use'] as $field) {
            if (blank($record[$field] ?? null)) {
                return ['ok' => false, 'why' => "the record for [{$name}] has no `{$field}`"];
            }
        }

        if (! in_array($record['use'], self::USES, true)) {
            return ['ok' => false, 'why' => "the record for [{$name}] says use [{$record['use']}]; it is ".implode(' or ', self::USES)];
        }

        $age = $this->ageInDays((string) $record['reviewed'], $today);

        if ($age === null) {
            return ['ok' => false, 'why' => "the record for [{$name}] has a review date that is not a date"];
        }

        if ($age < 0) {
            return ['ok' => false, 'why' => "the record for [{$name}] was reviewed on a date in the future"];
        }

        if ($age > (int) config('ai-licenses.max_review_age_days', 400)) {
            return ['ok' => false, 'why' => "the record for [{$name}] was reviewed {$age} days ago; review it again"];
        }

        foreach ((array) ($record['obligations'] ?? []) as $obligation => $place) {
            $problem = $this->placeProblem((string) $obligation, (string) $place, $record);

            if ($problem !== null) {
                return ['ok' => false, 'why' => "[{$name}] {$problem}"];
            }
        }

        return ['ok' => true, 'why' => "reviewed {$record['reviewed']} by {$record['by']}, {$record['use']}, obligations met in ".count((array) $record['obligations']).' place(s)'];
    }

    // An obligation is met in a file of the repository that exists; the attribution one has to contain the notice itself.
    private function placeProblem(string $obligation, string $place, array $record): ?string
    {
        if ($place === '' || str_contains($place, '..') || str_starts_with($place, '/')) {
            return "obligation [{$obligation}] has no usable place";
        }

        $path = base_path($place);

        if (! File::exists($path)) {
            return "obligation [{$obligation}] points at [{$place}], which does not exist";
        }

        if ($obligation === 'attribution' && filled($record['attribution_text'] ?? null) && ! str_contains(File::get($path), (string) $record['attribution_text'])) {
            return "the attribution [{$record['attribution_text']}] is not in [{$place}]";
        }

        return null;
    }

    private function ageInDays(string $date, CarbonImmutable $today): ?int
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            return null;
        }

        return $parsed->format('Y-m-d') === $date
            ? (int) $parsed->diffInDays($today, false)
            : null;
    }

    private function provider(string $name): array
    {
        $config = config('ai.providers')[$name] ?? [];

        if (is_string($config) && str_starts_with($config, '@')) {
            return $this->provider(substr($config, 1));
        }

        return (array) $config;
    }

    /** @return list<ModelRegistryEntry> */
    private function registryEntries(): array
    {
        try {
            return ModelRegistryEntry::query()->whereNotIn('stage', ['retired', 'rejected'])->get()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
