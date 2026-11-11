<?php

use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\File;

// Chapter 37, Topic 2: eval:route-table measures each role on the golden set and writes the routing table.
function routeGolden(array $cases): void
{
    $dir = sys_get_temp_dir().'/route-golden-'.uniqid();

    File::ensureDirectoryExists($dir);

    (new GoldenSet($dir))->write("$dir/cases.jsonl", array_map(fn (array $row) => GoldenCase::fromArray($row), $cases));

    app()->bind(GoldenSet::class, fn () => new GoldenSet($dir));
}

function routeCase(string $id, string $category): array
{
    return ['id' => $id, 'category' => $category, 'source' => 'human', 'customer' => 'acme', 'question' => "Question {$id}?", 'expected_behavior' => 'answer', 'must_include' => ['1.5%'], 'must_not_include' => ['INV-5120']];
}

function tempRoutes(): string
{
    $path = sys_get_temp_dir().'/ai-routes-'.uniqid().'.php';

    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

beforeEach(function () {
    spendControlsOn();

    config(['ai.eval.fixture_customers' => PHP_INT_MAX, 'ai.budgets.enabled' => false]);

    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($customer)->create();

    File::deleteDirectory(storage_path('eval'));
});

it('writes the table from pass rates in a file, and says what changed and what it is worth', function () {
    $rates = tempRoutes().'.json';

    file_put_contents($rates, json_encode(['howto' => ['local' => 0.97, 'mid' => 0.98, 'frontier' => 0.99], 'billing' => ['local' => 0.91, 'mid' => 0.96, 'frontier' => 0.98]]));

    $out = tempRoutes();

    [$code, $output] = artisanOutput('eval:route-table', ['--from' => $rates, '--out' => $out, '--margin' => 1]);

    $table = require $out;

    expect($code)->toBe(0)
        ->and($output)->toContain('local → cascade to mid')->toContain('wrote '.$out)->toContain('billing (')->toContain('projected hosted spend for support.reply: −')
        ->and($table['billing']['default']['chain'])->toBe(['local', 'mid', 'frontier'])
        ->and($table['howto']['default']['start'])->toBe('local');

    unlink($rates);
});

it('turns a category into a cascade on request', function () {
    $rates = tempRoutes().'.json';

    file_put_contents($rates, json_encode(['account' => ['local' => 0.94, 'mid' => 0.97]]));

    [, $output] = artisanOutput('eval:route-table', ['--from' => $rates, '--roles' => 'local,mid', '--cascade' => 'account']);

    expect($output)->toContain('local → cascade to mid')->toContain('would write 1 routes');

    unlink($rates);
});

it('measures each role on the golden set, one at a time, and routes by the result', function () {
    routeGolden([routeCase('a', 'billing'), routeCase('b', 'howto')]);

    Ai::fake()->for('support.reply')
        ->respondWith('The fee is 1.5% a month.')->respondWith('A reply with no figure.')            // the local role: passes billing, fails howto
        ->respondWith('The fee is 1.5% a month.', times: 2);                                          // the frontier role: passes both

    $out = tempRoutes();

    $saved = tempRoutes().'.json';

    [$code, $output] = artisanOutput('eval:route-table', ['--roles' => 'local,frontier', '--out' => $out, '--save' => $saved]);

    $table = require $out;

    expect($code)->toBe(0)
        ->and($table['billing']['default']['start'])->toBe('local')
        ->and($table['howto']['default']['start'])->toBe('frontier')
        ->and(json_decode(file_get_contents($saved), true))->toEqual(['billing' => ['local' => 1.0, 'frontier' => 1.0], 'howto' => ['local' => 0.0, 'frontier' => 1.0]]);

    unlink($saved);
});

it('puts the configuration back after measuring, and leaves the cascade off while it measures', function () {
    routeGolden([routeCase('a', 'billing')]);

    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $before = config('ai.features');

    artisanOutput('eval:route-table', ['--roles' => 'local', '--out' => tempRoutes()]);

    expect(config('ai.features'))->toBe($before)->and(config('ai.cascade.enabled'))->toBe(false)->and(config('ai.budgets.enabled'))->toBe(false);
});

it('says which role it could not measure instead of failing', function () {
    routeGolden([routeCase('a', 'billing')]);

    config(['ai.providers.frontier.api_key' => '']);

    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    [$code, $output] = artisanOutput('eval:route-table', ['--roles' => 'local,frontier', '--out' => tempRoutes()]);

    expect($code)->toBe(0)->and($output)->toContain('frontier: not measured (frontier is not set up)');
});

it('fails when nothing could be measured, or the rates file is missing', function () {
    $this->artisan('eval:route-table', ['--from' => '/no/such/file.json'])->assertFailed();

    config(['ai.providers.local' => []]);

    routeGolden([routeCase('a', 'billing')]);

    $this->artisan('eval:route-table', ['--roles' => 'mid'])->assertFailed();
});

it('takes --tiers as the same thing as --roles, and --write for the default path', function () {
    $rates = tempRoutes().'.json';

    file_put_contents($rates, json_encode(['howto' => ['local' => 0.99]]));

    [, $output] = artisanOutput('eval:route-table', ['--from' => $rates, '--tiers' => 'local']);

    expect($output)->toContain('would write 1 routes');

    unlink($rates);
});
