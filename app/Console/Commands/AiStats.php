<?php

namespace App\Console\Commands;

use App\Ai\Health\CircuitBreaker;
use App\Facades\Ai;
use App\Models\ModelCall;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiStats extends Command
{
    protected $signature = 'ai:stats {--feature=support.reply : The model_calls feature} {--since=24h : The window: 90m, 24h or 7d}';

    protected $description = 'Print the four context-engineering numbers (cache hit rates, tokens per answer, time to first token) for a feature';

    public function handle(): int
    {
        $seconds = $this->windowSeconds((string) $this->option('since'));

        if ($seconds === null) {
            $this->error('--since takes a number and a unit: 90m, 24h or 7d.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();

        $window = $this->calls($now->subSeconds($seconds), $now);

        $reference = $this->calls($now->subSeconds($seconds)->subDays(7), $now->subSeconds($seconds));

        $this->line(sprintf('%-24s %10s %12s   %s', '', 'window', '7-day avg', 'note'));

        $this->row('calls', number_format($window->count()), number_format((int) round($reference->count() * $seconds / (7 * 86400))));
        $this->row('prompt cache hit rate', $this->percent($this->promptCacheRate($window)), $this->percent($this->promptCacheRate($reference)), 'estimated locally from shared prefix bytes unless the provider reports cached tokens');
        $this->row('semantic cache hits', $this->percent($this->semanticRate($window)), $this->percent($this->semanticRate($reference)), 'of eligible (how-to) first questions');
        $this->row('tokens / answer', $this->number($this->tokensPerAnswer($window)), $this->number($this->tokensPerAnswer($reference)), 'input tokens per model reply');
        $this->line('  by slot: '.$this->slots($window));
        $this->row('citation flag rate', $this->percent($this->citationFlagRate($window)), $this->percent($this->citationFlagRate($reference)), 'grounded answers the citation checker flagged (first attempt)');
        $this->row('time to first token', $this->milliseconds($window->whereNotNull('ttft_ms')->avg('ttft_ms')), $this->milliseconds($reference->whereNotNull('ttft_ms')->avg('ttft_ms')), 'recorded by the streaming client only');

        $this->line('breaker: '.app(CircuitBreaker::class)->describe(Ai::providerFor((string) $this->option('feature'))));
        $this->line('routed: '.$this->routed($window, $now->subSeconds($seconds)));

        return self::SUCCESS;
    }

    private function calls(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ModelCall::query()
            ->where('feature', $this->option('feature'))
            ->where(fn (Builder $q) => $q->where('created_at', '>', $from)->where('created_at', '<=', $to))
            ->get();
    }

    // Chapter 25, Topic 9: where the window's requests were answered. Local is the role's own server; cache-only is the semantic cache (which keeps answering while the server is down); a fallback is any other provider; degraded-message is the honest notice; failed jobs are the queue's.
    private function routed(Collection $calls, CarbonImmutable $from): string
    {
        $role = Ai::providerFor((string) $this->option('feature'));

        $degraded = $calls->where('model', 'degraded');
        $cached = $calls->where('semantic_cache_hit', true);
        $rest = $calls->diff($degraded)->diff($cached);

        $parts = ['local '.$rest->where('provider', $role)->count(), 'cache-only '.$cached->count()];

        foreach ($rest->where('provider', '!=', $role)->groupBy('provider') as $provider => $group) {
            $parts[] = $provider.' '.$group->count();
        }

        $parts[] = 'degraded-message '.$degraded->count();
        $parts[] = 'failed jobs '.$this->failedJobs($from);

        return implode(' · ', $parts);
    }

    private function failedJobs(CarbonImmutable $from): int
    {
        try {
            return DB::table('failed_jobs')->where('failed_at', '>', $from)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function promptCacheRate(Collection $calls): ?float
    {
        $measured = $calls->whereNotNull('cached_input_tokens')->where('semantic_cache_hit', '!=', true);

        $prompt = $measured->sum('prompt_tokens');

        return $prompt > 0
            ? $measured->sum('cached_input_tokens') / $prompt
            : null;
    }

    private function semanticRate(Collection $calls): ?float
    {
        $hits = $calls->where('semantic_cache_hit', true)->count();
        $misses = $calls->filter(fn (ModelCall $call) => $call->semantic_cache_hit === false)->count();

        return $hits + $misses > 0
            ? $hits / ($hits + $misses)
            : null;
    }

    private function citationFlagRate(Collection $calls): ?float
    {
        $checked = $calls->whereNotNull('citation_errors');

        return $checked->isEmpty()
            ? null
            : $checked->where('citation_errors', '>', 0)->count() / $checked->count();
    }

    private function tokensPerAnswer(Collection $calls): ?float
    {
        $answers = $calls->where('finish_reason', 'stop')->where('semantic_cache_hit', '!=', true);

        return $answers->isEmpty()
            ? null
            : $answers->avg('prompt_tokens');
    }

    private function slots(Collection $calls): string
    {
        $measured = $calls->filter(fn (ModelCall $call) => ! empty($call->context_breakdown));

        if ($measured->isEmpty()) {
            return 'n/a';
        }

        $slots = [];

        foreach (['system', 'memories', 'summary', 'history', 'context', 'question'] as $slot) {
            $slots[] = $slot.' '.number_format((int) round($measured->avg(fn (ModelCall $call) => $call->context_breakdown[$slot] ?? 0)));
        }

        return implode(' · ', $slots);
    }

    private function row(string $label, string $window, string $reference, string $note = ''): void
    {
        $this->line(rtrim(sprintf('%-24s %10s %12s   %s', $label, $window, $reference, $note)));
    }

    private function percent(?float $rate): string
    {
        return $rate === null
            ? 'n/a'
            : round($rate * 100).'%';
    }

    private function number(null|int|float $value): string
    {
        return $value === null
            ? 'n/a'
            : number_format((int) round($value));
    }

    private function milliseconds(null|int|float $value): string
    {
        return $value === null
            ? 'n/a'
            : number_format((int) round($value)).' ms';
    }

    private function windowSeconds(string $since): ?int
    {
        if (preg_match('/^(\d+)([mhd])$/', $since, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * ['m' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
}
