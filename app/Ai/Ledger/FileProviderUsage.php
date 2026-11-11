<?php

namespace App\Ai\Ledger;

use InvalidArgumentException;

// A usage export a person downloaded from the provider's dashboard and saved as JSON: [{"date": "2026-11-10", "provider": "frontier", "model": "...", "input_tokens": 1, "output_tokens": 1, "cost_usd": 0.01}, ...].
// The same rows come from a provider's usage API when `usage_url` is set on the provider in config/ai.php (HttpProviderUsage).
final class FileProviderUsage implements ProviderUsage
{
    public function __construct(private string $path) {}

    public function forDay(string $provider, string $date): array
    {
        if (! is_file($this->path)) {
            throw new InvalidArgumentException("The usage file [{$this->path}] does not exist.");
        }

        $rows = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($rows)) {
            throw new InvalidArgumentException("The usage file [{$this->path}] is not a JSON list of rows.");
        }

        return UsageRows::forDay($rows, $provider, $date);
    }
}
