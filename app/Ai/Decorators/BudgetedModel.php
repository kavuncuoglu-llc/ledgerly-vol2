<?php

namespace App\Ai\Decorators;

use App\Ai\Budgets\BucketEmpty;
use App\Ai\Budgets\BudgetDecision;
use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Http\StreamTruncated;
use App\Ai\Ledger\Costing;
use Generator;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use Throwable;

// Chapter 36, Topic 9: inside tracing, outside redaction and logging. Checks the kill switch, takes the call's tokens from the provider's per-minute bucket, and reserves its estimated cost on every budget it falls under; either the call goes through
// or BudgetExhausted names the rung to fall to. After the call the reservation is settled to what the call really cost, and a call that fails gives its reservation back. The ledger entry is appended by a listener on ModelCalled (AppendLedgerEntry).
// If Redis cannot be reached the call is let through and the failure logged: a budget that fails closed is an outage, and the ledger still records what was spent.
final class BudgetedModel extends ModelDecorator
{
    public function __construct(
        ChatModel $inner,
        private BudgetGuard $guard,
        private KillSwitch $kill,
        private Counters $counters,
        private Costing $costing,
        private string $provider,
        private string $model,
    ) {
        parent::__construct($inner);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $decision = $this->admit($request);

        try {
            $response = $this->inner->chat($request);
        } catch (Throwable $e) {
            $this->release($decision);                                            // a failed call gives its reservation back

            throw $e;
        }

        $this->settle($decision, $response);

        return $response;
    }

    public function stream(ChatRequest $request): Generator
    {
        $decision = $this->admit($request);

        try {
            $stream = $this->inner->stream($request);

            foreach ($stream as $event) {
                yield $event;
            }

            $response = $stream->getReturn();
        } catch (StreamTruncated $e) {
            $this->settleUsage($decision, $e->usage ?? []);                          // what was generated before the cut was still billed

            throw $e;
        } catch (Throwable $e) {
            $this->release($decision);

            throw $e;
        }

        $this->settle($decision, $response);

        return $response;
    }

    private function admit(ChatRequest $request): BudgetDecision
    {
        $scope = $this->kill->activeScope($request->feature, $this->provider);

        if ($scope !== null) {
            Span::getCurrent()->setAttribute('ledgerly.budget.exhausted', 'kill:'.$scope);

            throw BudgetExhausted::killSwitch($scope, $request->feature);          // features fall to the bottom rung
        }

        try {
            $this->takeFromBucket($request);

            $decision = $this->guard->decide($request, $this->provider, $this->model);
        } catch (BucketEmpty|BudgetExhausted $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            Log::warning('The budget guard could not be reached: the call went through unguarded', ['feature' => $request->feature, 'error' => $e->getMessage()]);

            return BudgetDecision::allow(0);
        }

        if (! $decision->allowed) {
            Span::getCurrent()->setAttribute('ledgerly.budget.exhausted', $decision->budget->key);

            throw new BudgetExhausted($decision->budget, $decision->rung);
        }

        return $decision;
    }

    // Provider limits are in tokens per minute as well as requests: a bucket that refills continuously and allows a burst up to a minute's worth.
    private function takeFromBucket(ChatRequest $request): void
    {
        $perMinute = (int) config("ai.limits.{$this->provider}.tokens_per_minute", 0);

        if ($perMinute <= 0) {
            return;
        }

        $wait = $this->counters->takeFromBucket("{ai-budget}:bucket:provider:{$this->provider}", $perMinute, $perMinute / 60, $this->guard->estimateTokens($request));

        if ($wait > 0) {
            throw new BucketEmpty($this->provider, $wait);
        }
    }

    private function settle(BudgetDecision $decision, ChatResponse $response): void
    {
        $this->settleUsage($decision, $response->usage->toArray(), $response->model);
    }

    private function settleUsage(BudgetDecision $decision, array $usage, ?string $model = null): void
    {
        if ($decision->reserved === []) {
            return;
        }

        try {
            $actual = $this->costing->of(
                $this->provider,
                $model ?: $this->model,
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                (int) ($usage['cached_tokens'] ?? 0),
                (int) ($usage['cache_write_tokens'] ?? 0),
            )['micros'];

            $this->guard->settle($decision, $actual);
        } catch (Throwable $e) {
            report($e);                                                           // a counter that drifts is repaired by the nightly rebuild from the ledger
        }
    }

    private function release(BudgetDecision $decision): void
    {
        if ($decision->reserved === []) {
            return;
        }

        try {
            $this->guard->release($decision);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
