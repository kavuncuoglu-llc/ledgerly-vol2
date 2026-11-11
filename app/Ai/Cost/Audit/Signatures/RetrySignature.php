<?php

namespace App\Ai\Cost\Audit\Signatures;

use App\Ai\Cost\Audit\AuditCall;
use App\Ai\Cost\Audit\AuditData;
use App\Ai\Cost\Audit\Signature;
use App\Ai\Cost\Audit\SignatureResult;

// "The backlog that cleared itself too fast" (Chapter 35, Topic 6): jobs paid for more than once, and answers written long after the customer left.
final class RetrySignature implements Signature
{
    public function name(): string
    {
        return 'retries';
    }

    public function detect(AuditData $data): SignatureResult
    {
        $jobs = $data->calls->filter(fn (AuditCall $call) => $call->jobUuid !== null)->groupBy('jobUuid');

        $attempts = $jobs->map(fn ($calls) => $calls->map(fn (AuditCall $call) => $call->attempt ?? 1)->unique()->count());

        $average = $attempts->isEmpty()
            ? 1.0
            : $attempts->avg();

        $minutes = (int) config('ai.replies.freshness_minutes', 30);

        $summary = sprintf('%.2f paid attempts per job; %d answers after %d minutes', $average, $data->staleAnswers, $minutes);

        if ($average <= (float) config('ai.cost.audit.max_attempts_per_job', 1.25) && $data->staleAnswers === 0) {
            return SignatureResult::clear($this->name(), $summary);
        }

        $worst = $attempts->filter(fn (int $count) => $count > 1)->sortDesc()->take((int) config('ai.cost.audit.examples', 3));

        return SignatureResult::flagged(
            $this->name(),
            $summary,
            $worst->map(function (int $count, string $uuid) use ($jobs, $data) {
                $calls = $jobs[$uuid];

                return sprintf('job %s  %d attempts  $%.2f  trace %s', substr($uuid, 0, 8), $count, $calls->sum('usd'), $data->trace($calls->sortByDesc('usd')->first()->traceId));
            })->values()->all(),
            'retries restart paid steps, or stale questions are still being answered: check the provider limiter, the cached steps and the freshness check on AnswerQuestion',
        );
    }
}
