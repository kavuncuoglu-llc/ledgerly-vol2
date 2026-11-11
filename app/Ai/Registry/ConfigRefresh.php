<?php

namespace App\Ai\Registry;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Artisan;

// Chapter 44, Topic 7: "the config cache refreshes, and the next request goes to the old version". This process takes the new aliases at once; a cached config is rebuilt, and running workers are told to restart so they read it.
// On several hosts, run `registry:sync` (or `config:cache` after it) on each, in the deploy hook that follows a promotion.
class ConfigRefresh
{
    public function run(): void
    {
        $this->inProcess();

        if (! app()->configurationIsCached()) {
            return;
        }

        Artisan::call('config:cache');

        Artisan::call('queue:restart');
    }

    private function inProcess(): void
    {
        $providers = config('ai.providers');

        foreach (array_keys($providers) as $name) {
            if (is_string($name) && preg_match('/@(canary|shadow)$/', $name)) {
                unset($providers[$name]);
            }
        }

        foreach ((array) config('ai.registry.roles', []) as $registryName => $role) {
            $base = $providers[$role] ?? null;

            if (! is_array($base)) {
                continue;
            }

            $providers[$role]['model'] = Aliases::resolve("{$registryName}@production", $base['model']);

            $providers += Aliases::variants($role, $registryName, $providers[$role]);
        }

        config(['ai.providers' => $providers]);

        app(AiManager::class)->forget();
    }
}
