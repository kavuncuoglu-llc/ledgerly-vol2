<?php

namespace App\Ai\Ledger;

use App\Ai\Budgets\Teams;
use App\Ai\Cost\PriceMap;
use App\Models\FineTuneJob;
use Illuminate\Support\Facades\DB;

// Chapter 41, Topic 4 (the book's AiLedger::recordTraining): a finished hosted job is one ledger row of kind `training`, its trained tokens priced from the price map's `training` rates (dollars per million trained tokens, on the base model). With no rate the row is written at 0 and says why, so the audit has something to point at. Idempotent by entry id.
final class TrainingLedger
{
    public const FEATURE = 'training.fine_tune';

    public const SESSION_FEATURE = 'training.rented_gpu';

    public function __construct(private PriceMap $prices) {}

    public function record(FineTuneJob $job): bool
    {
        $rate = config('ai-pricing.training')[(string) $job->base_model] ?? null;

        $tokens = (int) $job->trained_tokens;

        $micros = $rate === null
            ? 0
            : (int) round($tokens * (float) $rate['per_million']);                       // $ per million tokens × tokens ÷ 1,000,000 dollars = micro-dollars

        return DB::table('ai_ledger')->insertOrIgnore([
            'occurred_at' => now(),
            'entry_id' => "ft:{$job->id}",
            'feature' => self::FEATURE,
            'team' => Teams::of(self::FEATURE),
            'provider' => $job->provider,
            'model' => (string) $job->base_model,
            'input_tokens' => $tokens,
            'cached_input_tokens' => 0,
            'cache_write_tokens' => 0,
            'output_tokens' => 0,
            'price_version' => $this->prices->version(),
            'cost_micro_usd' => $micros,
            'kind' => 'training',
            'reason' => $rate === null
                ? "unpriced training: set BILLING_FT_TRAIN_RATE (job {$job->provider_job_id})"
                : "job {$job->provider_job_id}",
        ]) === 1;
    }

    // Chapter 43, Topic 6: a rented training session writes its own `training` rows, so rented training shows up in the same spend reports as inference. One row when the session opens (cost 0: the ledger never holds an estimate) and one when it ends, with the hours billed times the hourly rate in micro-dollars. Idempotent by entry id.
    public function recordSession(string $sessionId, string $phase, string $provider, string $gpu, float $hours = 0.0, float $usdPerHour = 0.0, string $note = ''): bool
    {
        $micros = (int) round($hours * $usdPerHour * 1_000_000);

        return DB::table('ai_ledger')->insertOrIgnore([
            'occurred_at' => now(),
            'entry_id' => "session:{$sessionId}:{$phase}",
            'feature' => self::SESSION_FEATURE,
            'team' => Teams::of(self::SESSION_FEATURE),
            'provider' => $provider,
            'model' => $gpu,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'cache_write_tokens' => 0,
            'output_tokens' => 0,
            'price_version' => $this->prices->version(),
            'cost_micro_usd' => $micros,
            'kind' => 'training',
            'reason' => $phase === 'open'
                ? "session {$sessionId} opened ({$gpu})"
                : trim("session {$sessionId} {$phase}: ".round($hours, 3)." h at \${$usdPerHour} an hour. {$note}"),
        ]) === 1;
    }
}
