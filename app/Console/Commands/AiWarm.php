<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Health\CircuitBreaker;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\TokenCounter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

class AiWarm extends Command
{
    public const CACHE_KEY = 'ai:warm:';

    protected $signature = 'ai:warm
        {--provider=local : The provider (or tier) to warm}
        {--attempts=5 : How many times to try before giving up, ten seconds apart: a server that just started may still be loading}';

    protected $description = 'Send the support system message and a short question once, so the first customer does not pay the compile and cache cost (Chapter 25)';

    public function handle(AiManager $ai, TokenCounter $tokens, CircuitBreaker $breaker): int
    {
        $name = (string) (config('ai.tiers')[$this->option('provider')] ?? $this->option('provider'));

        try {
            $configured = $ai->configured($name);
        } catch (ProviderNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $configured) {
            $this->error("The '{$name}' provider is not set up. `php artisan ai:providers` shows which are.");

            return self::FAILURE;
        }

        $breaker->forget($name);                                                   // a warm-up is a probe: if the server answers, the breaker should close; if not, it opens again by itself

        $system = SupportPrompt::system();

        $first = $this->measure($ai, $name, $system, (int) $this->option('attempts'));

        if ($first === null) {
            return self::FAILURE;
        }

        $second = $this->measure($ai, $name, $system, 1) ?? $first;                // the second request is the one a customer would get

        Cache::put(self::CACHE_KEY.$name, ['at' => CarbonImmutable::now()->toIso8601String(), 'seconds' => $second], 86400);

        $this->line(sprintf('%s: warm (%.2f s; the first request took %.2f s) · prefix cached: system prompt (%d tokens)', $ai->model($name), $second, $first, $tokens->count($system)));

        return self::SUCCESS;
    }

    private function measure(AiManager $ai, string $name, string $system, int $attempts): ?float
    {
        $last = null;

        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            $started = hrtime(true);

            try {
                $ai->chat($name)->chat(ChatRequest::make(
                    [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => 'How do I add a customer?']],
                    ['max_tokens' => 1, 'profile' => 'deterministic'],
                    'ai.warm',
                ));
            } catch (Throwable $e) {
                $last = $e;

                if ($attempt < $attempts) {
                    $this->line("  attempt {$attempt} failed: ".$this->reason($e).'; trying again in 10 s');

                    Sleep::for(10)->seconds();
                }

                continue;
            }

            return (hrtime(true) - $started) / 1_000_000_000;
        }

        $this->error("Could not warm '{$name}': ".($last === null ? 'no attempt was made' : $this->reason($last)));

        return null;
    }

    private function reason(Throwable $e): string
    {
        return trim(strtok($e->getMessage(), "\n") ?: $e::class);
    }
}
