<?php

use App\Ai\Budgets\Counters;
use App\Ai\Contracts\ChatRequest;
use App\Facades\Ai;
use App\Models\AiLedgerEntry;
use Illuminate\Support\Facades\Context;

// Chapter 36: a hosted role with a price, budgets and the ledger switched on, and the call it would be made with.
function spendControlsOn(array $budgets = []): void
{
    config([
        'ai.budgets.enabled' => true,
        'ai.budgets.store' => 'array',
        'ai.ledger.enabled' => true,
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
        'ai.providers.frontier.privacy.dpa_signed' => true,
        'ai-pricing.models.frontier-example' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75, 'per' => 1_000_000, 'source' => 'test'],
        'ai-pricing.models.fake' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75, 'per' => 1_000_000, 'source' => 'test'],       // the model name FakeDriver answers with
        'ai.limits.frontier.tokens_per_minute' => 0,
        'ai.budgets.tenant_month_usd' => ['default' => 10.0],
        'ai.budgets.feature_day_usd' => ['*' => 10.0],
        'ai.budgets.app_hour_usd' => 100.0,
    ] + $budgets);

    Ai::forget();

    Context::flush();
}

function asTenant(int $tenantId, int $userId = 7, string $channel = 'app'): void
{
    Context::add('tenant_id', $tenantId);
    Context::add('user_id', $userId);
    Context::add('channel', $channel);
}

// A call whose estimate is exactly $microUsd: no input of consequence, and max_tokens at the output price ($15 per million).
function callCosting(int $microUsd, string $feature = 'support.reply'): ChatRequest
{
    return ChatRequest::make([['role' => 'user', 'content' => 'hi']], ['max_tokens' => (int) round($microUsd / 15)], $feature);
}

function spendCounters(): Counters
{
    return app(Counters::class);
}

function ledgerTotal(): int
{
    return (int) AiLedgerEntry::query()->sum('cost_micro_usd');
}
