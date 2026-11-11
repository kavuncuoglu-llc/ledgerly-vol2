<?php

use App\Ai\Routing\ExpectedCost;
use App\Ai\Routing\RoutePlan;
use App\Ai\Routing\RouteTableBuilder;
use App\Ai\Routing\RouteTableWriter;

// Chapter 37, Topic 2 and Figure 37-2: the routing table is a pure function of golden-set pass rates, so a change to it is a reviewed diff.
function figure372(): array
{
    return [
        'howto' => ['local' => 0.97, 'mid' => 0.98, 'frontier' => 0.99],
        'billing' => ['local' => 0.91, 'mid' => 0.96, 'frontier' => 0.98],
        'account' => ['local' => 0.94, 'mid' => 0.97, 'frontier' => 0.98],
        'safety' => ['local' => 0.78, 'mid' => 0.88, 'frontier' => 0.96],
        'policy' => ['local' => 0.93, 'mid' => 0.96, 'frontier' => 0.97],
    ];
}

function figure372Routes(): array
{
    return (new RouteTableBuilder)->build(figure372(), ['local', 'mid', 'frontier'], 0.95, 0.01);
}

it('reproduces Figure 37-2 from its pass rates', function () {
    $routes = figure372Routes();

    expect(collect($routes)->map(fn ($route) => $route['start'].($route['cascade'] ? '+cascade' : ''))->all())->toBe([
        'account' => 'mid',
        'billing' => 'local+cascade',
        'howto' => 'local',
        'policy' => 'mid',
        'safety' => 'frontier',
    ]);
});

it('always continues the chain up to the most capable role', function () {
    $routes = figure372Routes();

    expect($routes['billing']['chain'])->toBe(['local', 'mid', 'frontier'])
        ->and($routes['account']['chain'])->toBe(['mid', 'frontier'])
        ->and($routes['safety']['chain'])->toBe(['frontier'])
        ->and($routes['howto']['buffers'])->toBeTrue()
        ->and($routes['safety']['buffers'])->toBeFalse();                            // one role: it streams
});

it('needs the margin over the floor, not just the floor', function () {
    $rates = ['howto' => ['local' => 0.95, 'mid' => 0.97]];

    $routes = (new RouteTableBuilder)->build($rates, ['local', 'mid'], 0.95, 0.01);

    expect($routes['howto']['start'])->toBe('mid');                                    // 95% meets the floor and misses floor + margin

    $noMargin = (new RouteTableBuilder)->build($rates, ['local', 'mid'], 0.95, 0.0);

    expect($noMargin['howto']['start'])->toBe('local');
});

it('turns a category into a cascade on request (Exercise 2)', function () {
    $routes = (new RouteTableBuilder)->build(figure372(), ['local', 'mid', 'frontier'], 0.95, 0.01, forceCascade: ['account']);

    expect($routes['account']['start'])->toBe('local')->and($routes['account']['cascade'])->toBeTrue()
        ->and($routes['account']['chain'])->toBe(['local', 'mid', 'frontier']);
});

it('sends a category no role clears, or none was measured for, to the most capable role and a person', function () {
    $routes = (new RouteTableBuilder)->build(['hard' => ['local' => 0.5, 'mid' => 0.7, 'frontier' => 0.9], 'unmeasured' => []], ['local', 'mid', 'frontier'], 0.95, 0.01);

    expect($routes['hard']['start'])->toBe('frontier')->and($routes['hard']['why'])->toContain('no role clears the floor')
        ->and($routes['unmeasured']['start'])->toBe('frontier')->and($routes['unmeasured']['why'])->toContain('no role was measured');
});

it('skips a role it did not measure', function () {
    $routes = (new RouteTableBuilder)->build(['howto' => ['local' => 0.99]], ['local', 'mid', 'frontier'], 0.95, 0.01);

    expect($routes['howto']['start'])->toBe('local');

    $withoutLocal = (new RouteTableBuilder)->build(['howto' => ['mid' => 0.97, 'frontier' => 0.99]], ['local', 'mid', 'frontier'], 0.95, 0.01);

    expect($withoutLocal['howto']['start'])->toBe('mid');
});

it('prices a cascade as the cheap attempt plus the share that escalates (Topic 3)', function () {
    $costs = ['local' => 0.0, 'mid' => 0.002, 'frontier' => 0.013];

    $expected = ExpectedCost::of(['local', 'mid', 'frontier'], ['local' => 0.70, 'mid' => 0.667], $costs);

    expect($expected)->toEqualWithDelta(0.0019, 0.0001)
        ->and(1 - $expected / 0.013)->toBeGreaterThan(0.84);                           // about 85% less than straight to frontier
});

it('adds what a person\'s handling costs to the model spend (Figure 37-8)', function () {
    $current = ExpectedCost::totalPerConversation(0.030, 0.22, 4.00);
    $cheaper = ExpectedCost::totalPerConversation(0.012, 0.25, 4.00);

    expect($current)->toEqualWithDelta(0.91, 0.0001)->and($cheaper)->toEqualWithDelta(1.012, 0.0001)->and($cheaper)->toBeGreaterThan($current);
});

it('writes the file Chapter 44 reads, and the file loads back as the same table', function () {
    $routes = figure372Routes();

    $php = (new RouteTableWriter)->render($routes, ['written_on' => '2026-11-11', 'roles' => ['local', 'mid', 'frontier']]);

    $path = sys_get_temp_dir().'/ai-routes-'.bin2hex(random_bytes(4)).'.php';

    file_put_contents($path, $php);

    $loaded = require $path;

    unlink($path);

    expect($loaded['billing']['default']['start'])->toBe('local')
        ->and($loaded['billing']['default']['chain'])->toBe(['local', 'mid', 'frontier'])
        ->and($loaded['safety']['default']['start'])->toBe('frontier')
        ->and($loaded['_meta']['roles'])->toBe(['local', 'mid', 'frontier'])
        ->and($loaded['billing']['default']['rates']['local'])->toBe(0.91);
});

it('ships a table made from Figure 37-2, ready to review and regenerate', function () {
    $table = require config_path('ai-routes.php');

    expect($table['billing']['default']['cascade'])->toBeTrue()
        ->and($table['safety']['default']['start'])->toBe('frontier');
});

describe('a plan', function () {
    it('declines only a retrieval-checked category whose best passage is too far away', function () {
        $plan = new RoutePlan('howto', ['local', 'frontier'], true, true, 0.40, 0.50);

        expect($plan->declines(0.30))->toBeTrue()->and($plan->declines(0.45))->toBeFalse();

        $billing = new RoutePlan('billing', ['local', 'frontier'], true, false, 0.40, 0.50);

        expect($billing->declines(0.10))->toBeFalse();                                 // a billing question needs a tool, not a passage
    });

    it('starts one tier up when retrieval found little', function () {
        $plan = new RoutePlan('billing', ['local', 'mid', 'frontier'], true, false, 0.40, 0.50);

        expect($plan->chainFor(0.45))->toBe(['mid', 'frontier'])->and($plan->chainFor(0.70))->toBe(['local', 'mid', 'frontier']);

        $single = new RoutePlan('safety', ['frontier'], false, false, 0.40, 0.50);

        expect($single->chainFor(0.1))->toBe(['frontier']);
    });
});

it('refuses a category name that is not a plain identifier', function () {
    $hostile = ["x' => [], 'y' => system('id'), '" => ['local' => 0.99]];

    (new RouteTableBuilder)->build($hostile, ['local', 'mid', 'frontier'], 0.95, 0.01);
})->throws(InvalidArgumentException::class, 'routing category');

it('writes a category key as an escaped string even if one reaches the writer', function () {
    $name = "x'); file_put_contents('/tmp/pwned', '1'); //";

    $route = ['start' => 'local', 'chain' => ['local'], 'buffers' => false, 'cascade' => false, 'rates' => ['local' => 0.99], 'why' => 'test'];

    $source = (new RouteTableWriter)->render([$name => $route], ['version' => 1]);

    $path = tempnam(sys_get_temp_dir(), 'routes');

    file_put_contents($path, $source);

    $loaded = require $path;

    unlink($path);

    expect($loaded)->toHaveKey($name)->and(array_keys($loaded))->toBe(['_meta', $name]);
    expect(file_exists('/tmp/pwned'))->toBeFalse();
});
