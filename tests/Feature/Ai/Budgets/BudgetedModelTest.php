<?php

use App\Ai\Budgets\BucketEmpty;
use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\Ladder;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\BudgetedModel;
use App\Ai\Decorators\ModelDecorator;
use App\Ai\Events\BudgetThresholdReached;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Chapter 36, Topics 5 and 9: a call reserves its estimate on every budget it falls under, all or none, and settles to what it really cost.
beforeEach(function () {
    spendControlsOn();

    asTenant(7);
});

function frontierChat(?int $estimateMicros = null, string $feature = 'support.reply')
{
    return Ai::chat('frontier')->chat($estimateMicros === null
        ? callCosting(1_500, $feature)
        : callCosting($estimateMicros, $feature));
}

function budgetKeys(): array
{
    return collect(app(BudgetGuard::class)->budgetsFor('support.reply'))->mapWithKeys(fn ($budget) => [$budget->kind => $budget->key])->all();
}

it('puts the budget decorator just inside tracing, and leaves it out when budgets are off', function () {
    expect(Ai::chat('frontier')->unwrap())->not->toBeNull();

    $chain = fn ($model) => (new ReflectionProperty(ModelDecorator::class, 'inner'))->getValue($model);

    expect($chain(Ai::chat('frontier')))->toBeInstanceOf(BudgetedModel::class);

    config()->set('ai.budgets.enabled', false);

    Ai::forget();

    expect($chain(Ai::chat('frontier')))->not->toBeInstanceOf(BudgetedModel::class);
});

it('reserves across the tenant month and day, the feature day and the app hour, and settles to the real cost', function () {
    Ai::fake()->for('support.reply')->respondWith(str_repeat('a', 400));

    $before = app(BudgetGuard::class)->estimateMicroUsd(callCosting(1_500), 'frontier', 'frontier-example');

    $response = frontierChat();

    $keys = budgetKeys();

    $real = (int) round(($response->usage->promptTokens * 3.00 + $response->usage->completionTokens * 15.00));

    expect($keys)->toHaveKeys(['tenant.month', 'tenant.day', 'feature.day', 'app.hour'])
        ->and($before)->toBeGreaterThan(0);

    foreach ($keys as $key) {
        expect(spendCounters()->value($key))->toBe($real);            // the estimate was reserved, then replaced by what the call cost
    }

    expect($real)->toBeLessThan($before);
});

it('refuses a call all-or-none: when one budget is full nothing is reserved on the others', function () {
    config()->set('ai.budgets.tenant_day_share', 0.0001);                    // a day of $0.001 on a $10 month

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    $keys = budgetKeys();

    expect(fn () => frontierChat(5_000))->toThrow(BudgetExhausted::class);

    foreach ($keys as $key) {
        expect(spendCounters()->value($key))->toBe(0);
    }
});

it('names the budget and the first rung of the ladder', function () {
    config()->set('ai.budgets.tenant_day_share', 0.0001);

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    try {
        frontierChat(5_000);
    } catch (BudgetExhausted $e) {
        expect($e->budget->kind)->toBe('tenant.day')
            ->and($e->budget->key)->toContain(':tenant:7:day:')
            ->and($e->rung)->toBe(Ladder::CHEAPER_ROLE)
            ->and($e->isKillSwitch())->toBeFalse();

        return;
    }

    $this->fail('BudgetExhausted was not thrown.');
});

it('gives a background feature the pause rung, not a ladder', function () {
    config()->set('ai.budgets.feature_day_usd', ['triage.draft' => 0.0001]);

    Ai::fake()->for('triage.draft')->respondToEverythingWith('ok');

    try {
        frontierChat(5_000, 'triage.draft');
    } catch (BudgetExhausted $e) {
        expect($e->rung)->toBe(Ladder::PAUSE)
            ->and($e->resetsInSeconds())->toBeGreaterThan(0);

        return;
    }

    $this->fail('BudgetExhausted was not thrown.');
});

it('gives a failed call its reservation back', function () {
    Ai::fake()->for('support.reply')->respondWithError(new RuntimeException('the provider fell over'));

    expect(fn () => frontierChat())->toThrow(RuntimeException::class);

    foreach (budgetKeys() as $key) {
        expect(spendCounters()->value($key))->toBe(0);
    }
});

it('never stops a call that costs nothing, even with every budget spent: it is what a degraded feature falls to', function () {
    foreach (budgetKeys() as $key) {
        spendCounters()->set($key, (string) 999_999_999_999);
    }

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    expect(Ai::chat('local')->chat(callCosting(1_500))->content)->toBe('ok');
});

it('counts anonymous traffic against its own budget and not a tenant\'s', function () {
    Context::flush();

    asTenant(7, channel: 'anonymous');

    config()->set('ai.budgets.anonymous_features', []);

    $kinds = collect(app(BudgetGuard::class)->budgetsFor('widget.ask'))->pluck('kind')->all();

    expect($kinds)->toBe(['feature.day', 'anonymous.day', 'app.hour']);
});

it('works out a tenant\'s month from its plan and its day as a share of it', function () {
    $customer = Customer::factory()->create(['plan' => 'starter']);

    $tenant = Tenant::query()->firstOrCreate(['customer_id' => $customer->id], ['name' => $customer->name]);

    config()->set('ai.budgets.tenant_month_usd', ['starter' => 5.0, 'pro' => 25.0, 'default' => 25.0]);

    Cache::flush();

    asTenant($tenant->id);

    $budgets = collect(app(BudgetGuard::class)->budgetsFor('support.reply'))->keyBy('kind');

    expect($budgets['tenant.month']->limitMicros)->toBe(5_000_000)
        ->and($budgets['tenant.day']->limitMicros)->toBe(1_000_000);
});

it('sets a tenant\'s day to its own midnight', function () {
    $customer = Customer::factory()->create(['timezone' => 'Pacific/Auckland']);

    $tenant = Tenant::query()->firstOrCreate(['customer_id' => $customer->id], ['name' => $customer->name]);

    Cache::flush();

    asTenant($tenant->id);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 20:00:00', 'UTC'));                  // 09:00 on the 11th in Auckland

    $day = collect(app(BudgetGuard::class)->budgetsFor('support.reply'))->firstWhere('kind', 'tenant.day');

    expect($day->key)->toEndWith(':day:2026-11-11')
        ->and($day->resetsInSeconds)->toBeGreaterThan(14 * 3600)->toBeLessThan(15 * 3600);
});

it('takes a call\'s tokens from the provider\'s per-minute bucket and says how long to wait', function () {
    config()->set('ai.limits.frontier.tokens_per_minute', 2_000);

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    $big = ChatRequest::make([['role' => 'user', 'content' => str_repeat('word ', 1_800)]], ['max_tokens' => 500], 'support.reply');

    expect(fn () => Ai::chat('frontier')->chat($big))->toThrow(BucketEmpty::class);
});

it('lets a call through unguarded when the counters cannot be reached, and says so', function () {
    app()->instance(Counters::class, new class implements Counters
    {
        public function reserveAll(array $budgets, int $estimate): ?int
        {
            throw new RuntimeException('redis is down');
        }

        public function adjust(string $key, int $delta, int $ttl): void {}

        public function value(string $key): int
        {
            throw new RuntimeException('redis is down');
        }

        public function set(string $key, string $value, ?int $ttl = null): void {}

        public function get(string $key): ?string
        {
            return null;
        }

        public function forget(string $key): void {}

        public function anyExists(array $keys): bool
        {
            return false;
        }

        public function keys(string $prefix): array
        {
            return [];
        }

        public function takeFromBucket(string $key, float $capacity, float $refillPerSecond, float $weight): int
        {
            return 0;
        }
    });

    Ai::forget();

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    Log::spy();

    expect(Ai::chat('frontier')->chat(callCosting(1_500))->content)->toBe('ok');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'could not be reached'));
});

it('tells the owning team, and the tenant\'s admin, at 80% and only once', function () {
    Event::fake([BudgetThresholdReached::class]);

    config()->set('ai.budgets.tenant_day_share', 0.0001);                    // a $0.001 day: 1,000 micro-dollars

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    spendCounters()->set(budgetKeys()['tenant.day'], '790');

    frontierChat(15);                                                        // 790 + about 15: past 800

    frontierChat(15);

    Event::assertDispatchedTimes(BudgetThresholdReached::class, 1);
});

it('mails the owning team and the tenant admin when a threshold is reached', function () {
    config()->set('ai.teams.support.email', ['support@ledgerly.test']);

    $tenant = Tenant::factory()->create();

    User::factory()->inTenant($tenant)->create(['role' => 'owner', 'email' => 'owner@acme.example']);

    asTenant($tenant->id);

    config()->set('ai.budgets.tenant_day_share', 0.0001);

    Cache::flush();

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    spendCounters()->set(budgetKeys()['tenant.day'], '850');

    frontierChat(5);

    $recipients = collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->flatMap(fn ($sent) => collect($sent->getEnvelope()->getRecipients())->map(fn ($address) => $address->getAddress()))
        ->all();

    expect($recipients)->toContain('support@ledgerly.test', 'owner@acme.example');
});

it('settles a streamed call when the stream ends', function () {
    Ai::fake()->for('support.reply')->respondWithStream(['Fees ', 'start ', 'after 30 days.']);

    $stream = Ai::chat('frontier')->stream(callCosting(1_500));

    foreach ($stream as $event) {
        // the page reads these
    }

    $response = $stream->getReturn();

    $real = (int) round($response->usage->promptTokens * 3.00 + $response->usage->completionTokens * 15.00);

    expect(spendCounters()->value(budgetKeys()['feature.day']))->toBe($real);
});
