<?php

namespace App\Ai\Experiments;

use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Registry\Aliases;
use InvalidArgumentException;

// Chapter 30, Topic 4: the change under test. Here it is a system prompt (a version label and its text) and the provider role that answers with it; a model change is the same object with the other provider.
final readonly class Candidate
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $system,
        public string $provider = 'local',
        public ?string $replaces = null,
    ) {}

    // A PHP file that returns ['name' => …, 'version' => …, 'system' => …, 'provider' => …], or the name of a prompt class with VERSION and base().
    public static function from(string $source): self
    {
        if (str_starts_with($source, 'registry:')) {
            return self::fromRegistry(substr($source, 9));
        }

        if (class_exists($source)) {
            return new self(class_basename($source), $source::VERSION, $source::base());
        }

        $path = str_starts_with($source, '/')
            ? $source
            : base_path($source);

        if (! is_file($path)) {
            throw new InvalidArgumentException("No candidate at {$source}: expected a PHP file that returns an array, or a prompt class.");
        }

        $spec = require $path;

        return new self(
            name: $spec['name'] ?? pathinfo($path, PATHINFO_FILENAME),
            version: $spec['version'] ?? pathinfo($path, PATHINFO_FILENAME),
            system: $spec['system'] ?? null,
            provider: $spec['provider'] ?? 'local',
        );
    }

    // Chapter 44, Topic 7: a model version in a trial. `registry:local-billing-ft@canary` is the role local-billing-ft with the model the registry's billing@canary alias names (config/ai.php adds that provider while a version is in the trial).
    // It brings no system text of its own: the fine-tuned role is sent the short message it was trained on (SupportPrompt::boundTo), and `replaces` says which role in a route it takes the place of.
    private static function fromRegistry(string $provider): self
    {
        [$role, $slot] = array_pad(explode('@', $provider, 2), 2, '');

        if ($role === '' || ! in_array($slot, ['canary', 'shadow'], true)) {
            throw new InvalidArgumentException("A registry candidate is registry:<role>@canary or registry:<role>@shadow, not [registry:{$provider}].");
        }

        if (! isset(config('ai.providers')[$provider])) {
            throw new InvalidArgumentException("There is no provider [{$provider}]: no version of that role is in a {$slot} (registry:stage … --to={$slot}).");
        }

        $name = (string) array_search($role, (array) config('ai.registry.roles'), true);

        $version = Aliases::read()["{$name}@{$slot}"]['version'] ?? null;

        return new self(
            name: $version === null
                ? "{$role}-{$slot}"
                : "{$name}-v{$version}",
            version: FineTunedSupportPrompt::VERSION,
            system: null,
            provider: $provider,
            replaces: $role,
        );
    }
}
