<?php

namespace App\Ai\Registry;

use App\Models\ModelRegistryEntry;
use InvalidArgumentException;

// Chapter 44, Topics 8 and 9: what `registry:verify` prints and what `serve:fine-tune` refuses to load without. The base is checked against the Hub, every artifact file against the hash the registry recorded when it was made and against its signature, and the tools that made them are named.
class ArtifactVerifier
{
    public function __construct(private BaseModelCheck $base, private Signing $signing) {}

    /** @return list<VerificationLine> */
    public function verify(ModelRegistryEntry $entry): array
    {
        $lines = [];

        if (config('ai.registry.verify_base', true)) {
            array_push($lines, ...$this->base->check($entry->base, $entry->base_revision));
        }

        foreach ($this->files($entry) as $key => $path) {
            $lines[] = $this->artifact($entry, $key, $path);
        }

        $lines[] = $this->tools($entry);

        return $lines;
    }

    /** @param  list<VerificationLine>  $lines */
    public function passed(array $lines): bool
    {
        return collect($lines)->every(fn (VerificationLine $line) => $line->ok);
    }

    // The one check a server runs before it loads a file: the hash on disk is the hash recorded, and the signature (when signatures are required) is valid. Throws, naming the file, so a swapped file stops the load.
    public function assertLoadable(ModelRegistryEntry $entry, string $key): void
    {
        $path = $this->files($entry)[$key] ?? throw new InvalidArgumentException("{$entry->label()} has no {$key} artifact recorded: registry:add --export reads it from export.json.");

        $line = $this->artifact($entry, $key, $path);

        if (! $line->ok) {
            throw new InvalidArgumentException("Refusing to load {$entry->label()}: {$line->text()}");
        }
    }

    /** @return array<string, string> */
    public function files(ModelRegistryEntry $entry): array
    {
        return collect((array) ($entry->artifacts['files'] ?? []))
            ->map(fn (string $path) => is_file($path) || $entry->archived_at === null ? $path : app(ArtifactArchive::class)->locate($entry, $path))
            ->all();
    }

    private function artifact(ModelRegistryEntry $entry, string $key, string $path): VerificationLine
    {
        $subject = "{$key} ".basename($path);

        $recorded = $entry->artifacts[$key] ?? null;

        if (! is_file($path)) {
            return new VerificationLine($subject, false, "missing on disk ({$path})");
        }

        if ($recorded === null) {
            return new VerificationLine($subject, false, 'no hash was recorded when it was made');
        }

        $actual = 'sha256:'.hash_file('sha256', $path);

        if ($actual !== $recorded) {
            return new VerificationLine($subject, false, "sha256 does not match the registry ({$actual})");
        }

        return $this->signature($entry, $key, $path, $actual);
    }

    private function signature(ModelRegistryEntry $entry, string $key, string $path, string $hash): VerificationLine
    {
        $subject = "{$key} ".basename($path);

        $signed = $entry->signatures[$key] ?? null;

        if ($signed === null) {
            return config('ai.registry.signing.required', true)
                ? new VerificationLine($subject, false, 'sha256 matches · not signed (registry:sign records the pipeline\'s signature)')
                : new VerificationLine($subject, true, 'sha256 matches · unsigned (signatures are not required here)');
        }

        if (($signed['sha256'] ?? null) !== $hash) {
            return new VerificationLine($subject, false, 'the signature was made for a different file');
        }

        if (! $this->signing->for((string) ($signed['driver'] ?? ''))->verify($path, $hash, (string) $signed['signature'], (string) $signed['key_id'])) {
            return new VerificationLine($subject, false, "sha256 matches · signature NOT valid for key {$signed['key_id']}");
        }

        return new VerificationLine($subject, true, "sha256 matches · signature valid ({$signed['key_id']})");
    }

    private function tools(ModelRegistryEntry $entry): VerificationLine
    {
        $tools = (array) $entry->tools;

        if ($tools === []) {
            return new VerificationLine('tools', false, 'none recorded: pin and record llama.cpp, mlx-lm and peft per artifact');
        }

        $text = collect($tools)->map(fn ($version, $tool) => "{$tool} {$version}")->implode(' · ');

        return new VerificationLine('tools', true, "{$text} · recorded in entry");
    }
}
