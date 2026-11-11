<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

it('runs model work in its own supervisor on the ai queue, sized by AI_CONCURRENCY', function () {
    $supervisor = config('horizon.defaults.ai-interactive');

    expect($supervisor['queue'])->toBe(['ai'])
        ->and($supervisor['connection'])->toBe('redis')
        ->and($supervisor['balance'])->toBe('simple')
        ->and($supervisor['timeout'])->toBe(190)                                    // above AnswerQuestion::$timeout (180)
        ->and($supervisor['tries'])->toBe(3)
        ->and($supervisor['processes'])->toBe((int) env('AI_CONCURRENCY', 4));
});

it('keeps background model work on ai-batch so it never delays a reply', function () {
    expect(config('horizon.defaults.ai-batch.queue'))->toBe(['ai-batch'])
        ->and(config('horizon.defaults.ai-batch.maxProcesses'))->toBe(2)
        ->and(config('horizon.defaults.default.queue'))->toBe(['default'])
        ->and(array_keys(config('horizon.defaults')))->toBe(['ai-interactive', 'ai-batch', 'default']);
});

it('reads the worker count from the environment in every environment that runs workers', function () {
    foreach (['production', 'local'] as $environment) {
        $processes = config("horizon.environments.{$environment}.ai-interactive.processes")
            ?? config('horizon.defaults.ai-interactive.processes');

        expect($processes)->toBeInt()->toBeGreaterThanOrEqual(1);
    }
});

it('alerts when a customer waits too long behind other work', function () {
    expect(config('horizon.waits'))->toHaveKeys(['redis:default', 'redis:ai', 'redis:ai-batch'])
        ->and(config('horizon.waits.redis:ai'))->toBeLessThanOrEqual(60);
});

it('shows the Horizon dashboard to Ledgerly staff only outside local development', function () {
    $staff = User::factory()->support()->create();
    $customer = User::factory()->owner()->create();

    expect(Gate::forUser($staff)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();

    expect(Horizon::check(request()))->toBeFalse();                                 // testing is not local: a guest gets nothing
});

it('sends the queue to Redis and broadcasts through Reverb by default', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('QUEUE_CONNECTION=redis')
        ->toContain('BROADCAST_CONNECTION=reverb')
        ->toContain('AI_CONCURRENCY=')
        ->toContain('REDIS_CLIENT=predis');
});

it('only lets Reverb accept sockets from the origins you list', function () {
    $origins = config('reverb.apps.apps.0.allowed_origins');

    expect($origins)->not->toContain('*')
        ->and($origins)->toContain('localhost');
});

it('pins Horizon, Reverb, Predis and Livewire to exact versions', function () {
    $require = json_decode(file_get_contents(base_path('composer.json')), true)['require'];

    foreach (['laravel/horizon', 'laravel/reverb', 'predis/predis', 'livewire/livewire'] as $package) {
        expect($require[$package])->toMatch('/^\d+\.\d+\.\d+$/');
    }
});
