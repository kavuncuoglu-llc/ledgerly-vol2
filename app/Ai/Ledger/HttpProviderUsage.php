<?php

namespace App\Ai\Ledger;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

// The provider's usage endpoint (config ai.providers.<name>.usage_url), asked for one day and expected to answer the same rows as the file source. Providers' own usage APIs differ: put a small adapter or a gateway
// in front of the one you use that answers {"data": [{"model", "input_tokens", "output_tokens", "cost_usd"}]}. Not run against a real provider in this repository.
final class HttpProviderUsage implements ProviderUsage
{
    public function forDay(string $provider, string $date): array
    {
        $url = config("ai.providers.{$provider}.usage_url");

        if (blank($url)) {
            throw new InvalidArgumentException("The provider [{$provider}] has no usage_url, and no --usage file was given.");
        }

        $response = Http::withToken((string) config("ai.providers.{$provider}.api_key"))->timeout(20)->get($url, ['date' => $date])->throw();

        return UsageRows::forDay($response->json('data', []), $provider, $date);
    }
}
