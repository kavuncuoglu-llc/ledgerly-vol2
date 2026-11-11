<?php

namespace App\Jobs\Middleware;

use App\Ai\Budgets\BudgetExhausted;
use Closure;
use Illuminate\Support\Facades\Log;

// Chapter 36, Topic 5: a feature nobody is waiting on (a shadow answer, a reminder draft) does not degrade when its budget is spent: it pauses, and runs again when the period resets. The job is queued afresh with a delay,
// so waiting does not use up its attempts, and its failed-jobs row never says "budget".
final class PauseWhenBudgetSpent
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (BudgetExhausted $e) {
            $delay = $e->resetsInSeconds();

            Log::info('A background AI job paused: its budget is spent', ['job' => $job::class, 'budget' => $e->budget->key, 'resumes_in_seconds' => $delay]);

            $paused = clone $job;

            $paused->job = null;

            dispatch($paused)->delay($delay);

            $job->delete();

            return null;
        }
    }
}
