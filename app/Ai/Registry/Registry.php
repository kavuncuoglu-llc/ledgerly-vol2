<?php

namespace App\Ai\Registry;

use App\Ai\Budgets\Notifier;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Training\Dataset;
use App\Models\ExperimentSetting;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// Chapter 42, Build, and Chapter 44, Topics 6 and 7: adding a version, moving it up the ladder (candidate, shadow, canary, production) and back (previous, rejected, retired). A new version is a candidate, numbered after the last version of its name,
// with the provenance read from the dataset and the export record rather than typed: the dataset's hash is the one training read, the artifact hashes are the ones export_to_ollama.py computed. Every change of stage is a row in the entry's history with who made it and why.
final class Registry
{
    public function __construct(private TemplateGate $gate, private PromotionCriteria $criteria, private ConfigRefresh $refresh, private Notifier $notifier) {}

    /**
     * @param  array{name?: string, dataset: string, run?: ?string, export?: ?string, evaluation?: ?string, base?: ?string, base_revision?: ?string, tokenizer?: ?string, rows?: ?string, server?: string, skip_check?: bool, by?: string}  $options
     */
    public function add(string $model, array $options): ModelRegistryEntry
    {
        $export = $this->export($options['export'] ?? null);

        $this->refuseTinyModel($export, $options);

        $dataset = Dataset::named($options['dataset']);

        if (! $dataset->exists('train.jsonl')) {
            throw new InvalidArgumentException("No dataset {$options['dataset']} in storage/datasets: the registry records the hash of the data a model was trained on.");
        }

        if (! ($options['skip_check'] ?? false)) {
            $result = $this->gate->check($model, $options['tokenizer'] ?? null, $options['server'] ?? 'ollama', $options['rows'] ?? null);

            if (! $result['passed']) {
                throw new InvalidArgumentException("{$model} failed its template check, so it is not a candidate:\n{$result['output']}");
            }
        }

        $name = $options['name'] ?? 'billing';

        $taken = ModelRegistryEntry::query()->where('model', $model)->whereNotIn('stage', ['retired', 'rejected'])->first();

        if ($taken !== null) {
            throw new InvalidArgumentException("{$taken->label()} is registered as {$model} already. Export each version under its own name (ledgerly-billing-ft-v2) so that both can be served and a rollback is a change of alias, not a rebuild.");
        }

        $entry = ModelRegistryEntry::query()->create([
            'name' => $name,
            'version' => (int) ModelRegistryEntry::query()->where('name', $name)->max('version') + 1,
            'model' => $model,
            'base' => $options['base'] ?? $export['base'] ?? (string) config('ai.providers.'.(config('ai.registry.roles')[$name] ?? 'local-billing-ft').'.fine_tuned.base'),
            'base_revision' => $options['base_revision'] ?? null,
            'dataset' => $options['dataset'],
            'dataset_hash' => $dataset->hash('train.jsonl'),
            'training_run' => $options['run'] ?? null,
            'prompt_version' => SupportPrompt::FINE_TUNED_VERSION,
            'artifacts' => $this->artifacts($export, $dataset),
            'tools' => $export['tools'] ?? null,
            'evaluations' => $this->evaluations($options['evaluation'] ?? null),
            'stage' => 'candidate',
            'history' => [['stage' => 'candidate', 'at' => now()->toIso8601String(), 'by' => $options['by'] ?? 'registry:add', 'reason' => ($options['skip_check'] ?? false) ? 'added without the template check' : 'exported and passed the template check']],
        ]);

        return $entry;
    }

    public function find(string $name, int $version): ModelRegistryEntry
    {
        return ModelRegistryEntry::query()->where('name', $name)->where('version', $version)->first()
            ?? throw new InvalidArgumentException("There is no version {$version} of {$name} in the registry (registry:history {$name} lists them).");
    }

    // Chapter 44, Figure 44-5: `billing@production` is the model name a server knows the production version by. Read from the aliases file the registry writes, so config can call it before there is a database.
    public function resolve(string $alias, ?string $default = null): ?string
    {
        return Aliases::resolve($alias, $default);
    }

    public function productionFor(string $feature): ?ModelRegistryEntry
    {
        foreach ((array) (config('ai.registry.features')[$feature] ?? []) as $name) {
            $entry = ModelRegistryEntry::query()->where('name', $name)->where('stage', 'production')->first();

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /** @return Collection<int, ModelRegistryEntry> */
    public function history(string $name): Collection
    {
        return ModelRegistryEntry::query()->where('name', $name)->orderBy('version')->get();
    }

    // Figure 44-7: shadow, canary, and the ways back from them. A version moves one rung at a time; only one version of a name is in a trial at once, because the trial is one experiment.
    public function stage(string $name, int $version, string $to, string $by, ?int $percent = null, ?string $reason = null, ?string $evaluation = null): ModelRegistryEntry
    {
        $entry = $this->find($name, $version);

        $this->guardStage($entry, $to, $percent, $reason);

        $entry = DB::transaction(function () use ($entry, $to, $by, $percent, $reason, $evaluation) {
            $trial = in_array($to, ['shadow', 'canary'], true);

            $share = $trial
                ? ($percent ?? (int) config('ai.registry.shadow_percent', 10))
                : null;

            $entry->update([
                'stage' => $to,
                'percent' => $share,
                'evaluations' => $evaluation === null
                    ? $entry->evaluations
                    : [...(array) $entry->evaluations, ...($this->evaluations($evaluation) ?? [])],
                'history' => [...(array) $entry->history, $this->event($to, $by, $reason ?? $this->defaultReason($to, $share), $share)],
            ]);

            $this->switchExperiment($entry);

            return $entry;
        });

        $this->publish();

        $this->notifier->channel("Model registry: {$entry->label()} is now {$to}".($entry->percent === null ? '' : " at {$entry->percent}%").'.');

        return $entry;
    }

    // Promotion moves the production alias to this version and the old production version to `previous`, loaded and warm until `warm_until`. Without --force it is refused unless the decision record's criteria are all met by the stored results.
    public function promote(string $name, int $version, string $by, bool $force = false, ?string $reason = null): ModelRegistryEntry
    {
        $entry = $this->find($name, $version);

        if ($entry->stage === 'production') {
            throw new InvalidArgumentException("{$entry->label()} is already in production.");
        }

        if ($entry->stage === 'retired' && ! $force) {
            throw new InvalidArgumentException("{$entry->label()} is retired. Re-serve it with registry:rollback --to={$version}, or promote it with --force.");
        }

        if (! $force && $entry->stage !== 'canary') {
            throw new InvalidArgumentException("{$entry->label()} is {$entry->stage}: it is promoted from a canary (registry:stage {$name} {$version} --to=canary), or with --force.");
        }

        $failures = $this->criteria->failures($entry);

        if ($failures !== [] && ! $force) {
            throw new InvalidArgumentException("{$entry->label()} is not promoted: the decision record's criteria are not met.\n  - ".implode("\n  - ", $failures));
        }

        DB::transaction(function () use ($entry, $by, $force, $failures, $reason) {
            $this->demoteProduction($entry, $by);

            $note = $force && $failures !== []
                ? 'forced, criteria skipped: '.implode('; ', $failures)
                : ($force ? 'forced' : 'criteria met');

            $entry->update([
                'stage' => 'production',
                'percent' => null,
                'promoted_at' => now(),
                'warm_until' => null,
                'history' => [...(array) $entry->history, $this->event('production', $by, trim(($reason ?? 'promoted').' · '.$note))],
            ]);

            $this->switchExperiment($entry);
        });

        $this->publish();

        $this->notifier->channel("Model registry: {$entry->label()} is now production ({$entry->model}).");

        return $entry->refresh();
    }

    // Figure 44-7: the alias moves back, the config refreshes, and the next request goes to the old version. A previous version is still loaded, so there is nothing to convert, upload or restart; a retired one has to be loaded again first.
    public function rollback(string $name, string $reason, string $by, ?int $to = null): ModelRegistryEntry
    {
        $current = ModelRegistryEntry::query()->where('name', $name)->where('stage', 'production')->first();

        if ($current === null) {
            throw new InvalidArgumentException("There is no production version of {$name} to roll back from.");
        }

        $target = $to === null
            ? ModelRegistryEntry::query()->where('name', $name)->where('stage', 'previous')->first()
            : $this->find($name, $to);

        if ($target === null) {
            throw new InvalidArgumentException("{$name} has no previous version kept warm. Name the version to go back to with --to.");
        }

        if ($target->is($current)) {
            throw new InvalidArgumentException("{$target->label()} is already in production.");
        }

        if ($target->stage === 'retired') {
            app(ServeFineTune::class)->reload($target);
        }

        DB::transaction(function () use ($name, $current, $target, $reason, $by) {
            $current->update([
                'stage' => 'rejected',
                'percent' => null,
                'history' => [...(array) $current->history, $this->event('rejected', $by, "rolled back: {$reason}")],
            ]);

            $target->update([
                'stage' => 'production',
                'percent' => null,
                'promoted_at' => now(),
                'warm_until' => null,
                'retired_at' => null,
                'history' => [...(array) $target->history, $this->event('production', $by, "rolled back from v{$current->version}: {$reason}")],
            ]);

            $this->closeExperiment($name);
        });

        $this->publish();

        $this->notifier->oncall("Model registry: {$name} rolled back from v{$current->version} to v{$target->version}", $reason);

        return $target->refresh();
    }

    // The canary's guardrail fired: the trial ends, the version is rejected with the reason, and the production version keeps all the traffic.
    public function stopTrial(string $name, string $reason, string $by): ?ModelRegistryEntry
    {
        $entry = ModelRegistryEntry::query()->where('name', $name)->whereIn('stage', ['shadow', 'canary'])->first();

        if ($entry === null) {
            return null;
        }

        return $this->stage($name, $entry->version, 'rejected', $by, reason: $reason);
    }

    /** @return Collection<int, ModelRegistryEntry> the previous versions whose warm period has ended, now retired, unloaded and archived */
    public function retireExpired(string $by = 'registry:retire'): Collection
    {
        $expired = ModelRegistryEntry::query()->where('stage', 'previous')->where('warm_until', '<=', now())->get();

        foreach ($expired as $entry) {
            app(ServeFineTune::class)->unload($entry);

            $entry->update([
                'stage' => 'retired',
                'retired_at' => now(),
                'archived_at' => app(ArtifactArchive::class)->archive($entry) ? now() : null,
                'history' => [...(array) $entry->history, $this->event('retired', $by, 'warm period ended: unloaded, artifacts archived')],
            ]);
        }

        $this->publish();

        return $expired;
    }

    // The aliases file says what each alias resolves to now; config and the servers' role names follow it.
    public function publish(): void
    {
        $aliases = [];

        foreach (ModelRegistryEntry::query()->whereIn('stage', ['production', 'previous', 'canary', 'shadow'])->get() as $entry) {
            $aliases["{$entry->name}@{$entry->stage}"] = ['version' => $entry->version, 'model' => $entry->model];
        }

        Aliases::write($aliases);

        $this->refresh->run();
    }

    private function guardStage(ModelRegistryEntry $entry, string $to, ?int $percent, ?string $reason): void
    {
        $allowed = [
            'shadow' => ['candidate'],
            'canary' => ['shadow', 'canary'],
            'candidate' => ['shadow', 'canary'],
            'rejected' => ['candidate', 'shadow', 'canary'],
        ];

        if (! isset($allowed[$to])) {
            throw new InvalidArgumentException('--to is shadow, canary, candidate (to stop a trial) or rejected; production is registry:promote.');
        }

        if (! in_array($entry->stage, $allowed[$to], true)) {
            throw new InvalidArgumentException("{$entry->label()} is {$entry->stage}: it can go to {$to} only from ".implode(' or ', $allowed[$to]).'.');
        }

        if ($percent !== null && ($percent < 0 || $percent > 100)) {
            throw new InvalidArgumentException('--percent is 0 to 100.');
        }

        if ($to === 'rejected' && blank($reason)) {
            throw new InvalidArgumentException('A rejected version records why: pass --reason.');
        }

        $other = ModelRegistryEntry::query()->where('name', $entry->name)->whereIn('stage', ['shadow', 'canary'])->whereKeyNot($entry->id)->first();

        if (in_array($to, ['shadow', 'canary'], true) && $other !== null) {
            throw new InvalidArgumentException("{$other->label()} is in {$other->stage} already: one version of a name is tried at a time. Stop it first.");
        }
    }

    private function defaultReason(string $to, ?int $share): string
    {
        return match ($to) {
            'shadow' => "copies of {$share}% of real questions answered by the candidate, never shown",
            'canary' => "serving {$share}% of customers' billing questions",
            'candidate' => 'trial stopped',
            default => $to,
        };
    }

    private function event(string $stage, string $by, string $reason, ?int $percent = null): array
    {
        return array_filter(['stage' => $stage, 'at' => now()->toIso8601String(), 'by' => $by, 'reason' => $reason, 'percent' => $percent], fn ($value) => $value !== null);
    }

    private function demoteProduction(ModelRegistryEntry $incoming, string $by): void
    {
        ModelRegistryEntry::query()->where('name', $incoming->name)->where('stage', 'previous')->whereKeyNot($incoming->id)->get()->each(function (ModelRegistryEntry $old) use ($by) {
            app(ServeFineTune::class)->unload($old);

            $old->update(['stage' => 'retired', 'retired_at' => now(), 'history' => [...(array) $old->history, $this->event('retired', $by, 'a newer version was promoted over its successor')]]);
        });

        $production = ModelRegistryEntry::query()->where('name', $incoming->name)->where('stage', 'production')->whereKeyNot($incoming->id)->first();

        $production?->update([
            'stage' => 'previous',
            'warm_until' => now()->addDays((int) config('ai.registry.warm_days', 7)),
            'history' => [...(array) $production->history, $this->event('previous', $by, "replaced by v{$incoming->version}; kept loaded and warm")],
        ]);
    }

    // A trial is one experiment, named for the fine-tune, whose candidate is the role's canary or shadow variant (Candidate::from reads `registry:` sources).
    private function switchExperiment(ModelRegistryEntry $entry): void
    {
        $role = (string) (config('ai.registry.roles')[$entry->name] ?? '');

        if ($entry->stage === 'shadow' && $role !== '') {
            $this->setExperiment($entry->name, 'shadow', (int) $entry->percent, "registry:{$role}@shadow");

            return;
        }

        if ($entry->stage === 'canary' && $role !== '') {
            $this->setExperiment($entry->name, 'ab', (int) $entry->percent, "registry:{$role}@canary");

            return;
        }

        $this->closeExperiment($entry->name);
    }

    private function setExperiment(string $name, string $mode, int $percent, string $candidate): void
    {
        ExperimentSetting::query()->updateOrCreate(['name' => "registry-{$name}"], ['mode' => $mode, 'percent' => $percent, 'candidate' => $candidate]);
    }

    private function closeExperiment(string $name): void
    {
        ExperimentSetting::query()->where('name', "registry-{$name}")->update(['mode' => 'off', 'percent' => 0]);
    }

    // Chapter 45, Build: the 14M-parameter model in training/tiny/ is for understanding. It is never exported, registered or served, so nothing that comes from that directory (an export record there, an artifact file in it, a model named for it) becomes a version.
    private function refuseTinyModel(array $export, array $options): void
    {
        $tiny = (string) realpath(base_path('training/tiny'));

        $paths = array_filter([$export['_dir'] ?? null, ...array_values((array) ($export['files'] ?? [])), ...array_values((array) ($export['_files'] ?? [])), $options['export'] ?? null, $options['rows'] ?? null]);

        foreach ($paths as $path) {
            $absolute = str_starts_with((string) $path, '/')
                ? (string) $path
                : (string) ($export['_dir'] ?? base_path()).'/'.$path;

            $real = realpath($absolute) ?: $absolute;

            if ($tiny !== '' && str_starts_with($real, $tiny)) {
                throw new InvalidArgumentException("{$path} is in training/tiny/: the from-scratch model of Chapter 45 is for understanding, and registry:add refuses anything that comes from there.");
            }
        }

        if (str_contains((string) ($export['name'] ?? ''), 'tiny-gpt')) {
            throw new InvalidArgumentException('The export record names the Chapter 45 tiny GPT (tiny-gpt): it is never registered.');
        }
    }

    private function export(?string $path): array
    {
        if ($path === null) {
            return [];
        }

        $absolute = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($absolute)) {
            throw new InvalidArgumentException("No export record at {$absolute}: export_to_ollama.py writes it next to the Modelfile.");
        }

        return ['_dir' => dirname($absolute)] + (json_decode((string) file_get_contents($absolute), true) ?? []);
    }

    // The export record names each artifact's file relative to itself; the registry keeps them absolute so a server can be pointed at them and a verification can hash them again.
    private function files(array $export): array
    {
        $files = [];

        foreach ((array) ($export['files'] ?? []) as $key => $relative) {
            $files[$key] = str_starts_with((string) $relative, '/')
                ? (string) $relative
                : $export['_dir'].'/'.$relative;
        }

        return $files;
    }

    private function artifacts(array $export, Dataset $dataset): array
    {
        return array_filter([
            ...($export['artifacts'] ?? []),
            'quantization' => $export['quantization'] ?? null,
            'backend' => $export['backend'] ?? null,
            'files' => $this->files($export) ?: null,
            'dataset_manifest' => $dataset->exists('manifest.json') ? $dataset->hash('manifest.json') : null,
        ], fn ($value) => $value !== null);
    }

    private function evaluations(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }

        $absolute = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($absolute)) {
            throw new InvalidArgumentException("No comparison at {$absolute}: run eval:compare first.");
        }

        $result = json_decode((string) file_get_contents($absolute), true);

        return [['at' => $result['ran_on'] ?? null, 'set' => $result['set'] ?? null, 'cases' => $result['cases'] ?? null, 'roles' => $result['results'] ?? []]];
    }
}
