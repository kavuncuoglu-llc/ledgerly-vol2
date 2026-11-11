<?php

namespace App\Ai\FineTuning;

use InvalidArgumentException;

// Chapter 41, Topic 4: `$this->clients->for($job->provider)`. Which driver a provider uses is configuration (ai.fine_tuning.drivers): `openai` for an OpenAI-shaped API, `fake` for the offline Build.
class FineTuningClients
{
    public function for(string $provider): FineTuningClient
    {
        $driver = $this->driverName($provider);

        return match ($driver) {
            'openai' => new OpenAiFineTuningClient(
                (string) config("ai.providers.{$provider}.base_url"),
                (string) config("ai.providers.{$provider}.api_key"),
                (int) config("ai.providers.{$provider}.timeout", 60),
            ),
            'fake' => new FakeFineTuningClient(
                (string) config('ai.fine_tuning.fake.directory'),
                (int) config('ai.fine_tuning.fake.seconds'),
            ),
            default => throw new InvalidArgumentException("[{$provider}] has no fine-tuning driver [{$driver}]: openai or fake."),
        };
    }

    public function driverName(string $provider): string
    {
        $driver = config('ai.fine_tuning.drivers')[$provider] ?? null;

        return $driver ?? throw new InvalidArgumentException("No fine-tuning driver for [{$provider}]: set ai.fine_tuning.drivers.{$provider}. Providers with one: ".implode(', ', array_keys(config('ai.fine_tuning.drivers', []))).'.');
    }
}
