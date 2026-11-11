<?php

namespace App\Http\Controllers;

use App\Ai\AiManager;
use App\Ai\Health\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

// Chapter 25, Topic 5: the one thing the model servers cannot tell Prometheus, the app's own view of them. The circuit breaker per provider and the queue's failed jobs.
// Off unless OPS_METRICS_TOKEN is set (404 otherwise, so the route does not even admit it exists); Prometheus sends the token as a bearer key (ops/monitoring/prometheus.yml).
final class OpsMetricsController extends Controller
{
    public function __invoke(Request $request, AiManager $ai, CircuitBreaker $breaker): Response
    {
        $token = (string) config('ai.metrics_token');

        abort_if($token === '', 404);

        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $lines = [
            '# HELP ledgerly_ai_breaker_open 1 when the circuit breaker has the provider marked down.',
            '# TYPE ledgerly_ai_breaker_open gauge',
        ];

        $providers = array_keys(array_filter($ai->providers(), fn (array $provider) => ($provider['driver'] ?? '') === 'openai-compatible' && $ai->configured($provider['name'])));

        foreach ($providers as $provider) {
            $lines[] = sprintf('ledgerly_ai_breaker_open{provider="%s"} %d', $provider, $breaker->isOpen($provider) ? 1 : 0);
        }

        $lines[] = '# HELP ledgerly_ai_breaker_failures Consecutive failures counted toward opening the breaker.';
        $lines[] = '# TYPE ledgerly_ai_breaker_failures gauge';

        foreach ($providers as $provider) {
            $lines[] = sprintf('ledgerly_ai_breaker_failures{provider="%s"} %d', $provider, $breaker->snapshot($provider)['failures']);
        }

        $lines[] = '# HELP ledgerly_ai_failed_jobs_total Rows in failed_jobs.';
        $lines[] = '# TYPE ledgerly_ai_failed_jobs_total counter';
        $lines[] = 'ledgerly_ai_failed_jobs_total '.$this->failedJobs();

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    private function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
