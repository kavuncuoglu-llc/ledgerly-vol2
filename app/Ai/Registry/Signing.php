<?php

namespace App\Ai\Registry;

use App\Models\ModelRegistryEntry;
use InvalidArgumentException;

// Chapter 44, Topic 8: the pipeline signs each artifact when it makes it (`registry:sign`), and every reader verifies. The driver is config('ai.registry.signing.driver'): ed25519 (built in) or cosign.
class Signing
{
    public function for(string $driver): Signer
    {
        return match ($driver) {
            'ed25519' => new Ed25519Signer,
            'cosign' => new CosignSigner,
            default => throw new InvalidArgumentException("The signature driver is ed25519 or cosign, not [{$driver}]."),
        };
    }

    public function configured(): Signer
    {
        return $this->for((string) config('ai.registry.signing.driver', 'ed25519'));
    }

    /** @return array<string, array> what was signed, by artifact key; already signed artifacts are left as they are unless $again */
    public function signAll(ModelRegistryEntry $entry, bool $again = false): array
    {
        $signer = $this->configured();

        $signatures = (array) $entry->signatures;

        foreach ((array) ($entry->artifacts['files'] ?? []) as $key => $path) {
            $recorded = $entry->artifacts[$key] ?? null;

            if (! is_file($path) || $recorded === null) {
                throw new InvalidArgumentException("Cannot sign {$key}: ".(is_file($path) ? 'no hash was recorded for it' : "{$path} is missing").'.');
            }

            $hash = 'sha256:'.hash_file('sha256', $path);

            if ($hash !== $recorded) {
                throw new InvalidArgumentException("Cannot sign {$key}: the file's sha256 is not the one the registry recorded. Something changed it after it was made.");
            }

            if (isset($signatures[$key]) && ! $again) {
                continue;
            }

            $signatures[$key] = $signer->sign($path, $hash) + ['sha256' => $hash, 'driver' => $signer->name(), 'at' => now()->toIso8601String()];
        }

        $entry->update(['signatures' => $signatures]);

        return $signatures;
    }
}
