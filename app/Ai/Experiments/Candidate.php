<?php

namespace App\Ai\Experiments;

use InvalidArgumentException;

// Chapter 30, Topic 4: the change under test. Here it is a system prompt (a version label and its text) and the provider role that answers with it; a model change is the same object with the other provider.
final readonly class Candidate
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $system,
        public string $provider = 'local',
    ) {}

    // A PHP file that returns ['name' => …, 'version' => …, 'system' => …, 'provider' => …], or the name of a prompt class with VERSION and base().
    public static function from(string $source): self
    {
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
}
