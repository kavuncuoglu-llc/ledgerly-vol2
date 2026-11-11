<?php

use App\Ai\Budgets\ArrayCounters;
use App\Ai\Budgets\Notifier;
use App\Ai\Budgets\SpendBreaker;
use App\Ai\Events\GuardrailBreached;
use App\Ai\Registry\Aliases;
use App\Ai\Routing\RouteTableWriter;
use App\Models\AiLedgerEntry;
use App\Models\ModelRegistryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Support\FineTuningWorld;
use Tests\Support\RegistryWorld;

// Chapter 44: the small guarantees around the registry, the alias file, the route table writer and the spend breaker.
beforeEach(function () {
    RegistryWorld::boot();
});

afterEach(function () {
    RegistryWorld::clean();
});

it('refuses an alias or a model name that could be anything but a name, on the way in and on the way out', function () {
    expect(fn () => Aliases::write(['billing@production' => ['version' => 1, 'model' => "x'];phpinfo();//"]]))->toThrow(InvalidArgumentException::class, 'not a model name')
        ->and(fn () => Aliases::write(['Billing Prod' => ['version' => 1, 'model' => 'm']]))->toThrow(InvalidArgumentException::class, 'not a registry alias')
        ->and(fn () => Aliases::write(['billing@everything' => ['version' => 1, 'model' => 'm']]))->toThrow(InvalidArgumentException::class);

    File::put(Aliases::path(), json_encode(['billing@production' => ['version' => 1, 'model' => '$(rm -rf /)'], 'billing@previous' => ['version' => 0, 'model' => 'ok-model:v1'], '../x@production' => ['model' => 'm']]));

    expect(Aliases::read())->toBe(['billing@previous' => ['version' => 0, 'model' => 'ok-model:v1']])
        ->and(Aliases::resolve('billing@production', 'fallback'))->toBe('fallback');

    File::put(Aliases::path(), 'not json');

    expect(Aliases::read())->toBe([]);
});

it('does not register two live versions under one model name', function () {
    config(['ai.registry.verify_base' => false]);

    FineTuningWorld::dataset();

    RegistryWorld::entry('billing', 1, 'production', ['model' => 'ledgerly-billing-ft']);

    $this->artisan('registry:add', ['model' => 'ledgerly-billing-ft', '--dataset' => 'billing-v1', '--no-check' => true])->expectsOutputToContain('Export each version under its own name')->assertExitCode(1);

    expect(ModelRegistryEntry::query()->count())->toBe(1);
});

it('keeps a hand-written residency route when the route table is written again', function () {
    $routes = ['billing' => ['start' => 'local', 'chain' => ['local', 'mid'], 'buffers' => true, 'cascade' => true, 'rates' => ['local' => 0.97], 'why' => 'x']];

    $residency = ['billing' => ['start' => 'local-billing-ft', 'chain' => ['local-billing-ft', 'mid-in-region'], 'then' => 'person', 'why' => "it's in region"]];

    $php = (new RouteTableWriter)->render($routes, ['written_on' => '2026-11-11'], $residency);

    $path = sys_get_temp_dir().'/routes-'.bin2hex(random_bytes(4)).'.php';

    File::put($path, $php);

    $written = require $path;

    File::delete($path);

    expect($written['billing']['residency'])->toBe($residency['billing'])
        ->and($written['billing']['default']['start'])->toBe('local');
});

it('raises a guardrail breach for the feature when the spend breaker opens on it, so a fresh promotion can be rolled back', function () {
    Event::fake([GuardrailBreached::class]);

    $now = CarbonImmutable::parse('2026-11-11 12:00:00');

    foreach (range(1, 20) as $i) {
        AiLedgerEntry::query()->create(['entry_id' => "e{$i}", 'occurred_at' => $now->subMinutes(5), 'feature' => 'support.reply', 'tenant_id' => 1, 'provider' => 'frontier', 'model' => 'm', 'kind' => 'chat', 'team' => 'support', 'cost_micro_usd' => 400_000, 'input_tokens' => 1, 'output_tokens' => 1, 'cached_input_tokens' => 0, 'cache_write_tokens' => 0, 'price_version' => 'test']);
    }

    (new SpendBreaker(new ArrayCounters, new Notifier))->evaluate($now);

    Event::assertDispatched(GuardrailBreached::class, fn (GuardrailBreached $event) => $event->feature === 'support.reply' && $event->metric === 'spend_breaker' && $event->value === 8.0);
});

// Chapter 45, Build: the model in training/tiny/ is for understanding; registry:add refuses anything that comes from that directory.
it('refuses an export record, an artifact or a name that comes from training/tiny', function () {
    FineTuningWorld::dataset();

    $tiny = base_path('training/tiny/ckpt-test-'.bin2hex(random_bytes(3)));

    File::ensureDirectoryExists($tiny);

    File::put($tiny.'/export.json', json_encode(['name' => 'ledgerly-tiny', 'base' => 'none', 'artifacts' => ['gguf' => 'sha256:aa'], 'files' => ['gguf' => 'tiny.gguf']]));

    $this->artisan('registry:add', ['model' => 'tiny', '--dataset' => 'billing-v1', '--no-check' => true, '--export' => $tiny.'/export.json'])
        ->expectsOutputToContain('is in training/tiny/')
        ->assertExitCode(1);

    $elsewhere = RegistryWorld::$directory.'/export.json';

    File::put($elsewhere, json_encode(['name' => 'tiny-gpt-v1', 'base' => 'none', 'artifacts' => []]));

    $this->artisan('registry:add', ['model' => 'tiny', '--dataset' => 'billing-v1', '--no-check' => true, '--export' => $elsewhere])
        ->expectsOutputToContain('names the Chapter 45 tiny GPT')
        ->assertExitCode(1);

    File::put($elsewhere, json_encode(['name' => 'fine', 'base' => 'b', 'artifacts' => ['adapter' => 'sha256:aa'], 'files' => ['adapter' => $tiny.'/adapter.safetensors']]));

    $this->artisan('registry:add', ['model' => 'tiny', '--dataset' => 'billing-v1', '--no-check' => true, '--export' => $elsewhere])
        ->expectsOutputToContain('is in training/tiny/')
        ->assertExitCode(1);

    File::deleteDirectory($tiny);

    expect(ModelRegistryEntry::query()->count())->toBe(0);
});

it('adds a version in the form of Figure 46-8: the fine-tune\'s name, then its number', function () {
    FineTuningWorld::dataset();

    $this->artisan('registry:add', ['model' => 'support', 'version' => '1', '--dataset' => 'billing-v1', '--no-check' => true])->assertExitCode(0);

    $entry = ModelRegistryEntry::query()->first();

    expect($entry->name)->toBe('support')->and($entry->version)->toBe(1)->and($entry->model)->toBe('support-v1')
        ->and($entry->base)->toBe('meta-llama/Llama-3.1-8B-Instruct');

    $this->artisan('registry:add', ['model' => 'support', 'version' => '5', '--dataset' => 'billing-v1', '--no-check' => true])->expectsOutputToContain('The next version of support is 2, not 5')->assertExitCode(1);

    $this->artisan('registry:add', ['model' => 'support', 'version' => '2', '--model' => 'support-adapter-b', '--dataset' => 'billing-v1', '--no-check' => true])->assertExitCode(0);

    expect(ModelRegistryEntry::query()->where('version', 2)->value('model'))->toBe('support-adapter-b');
});
