<?php

use Illuminate\Support\Facades\File;

// Chapter 46, Topic 5 and Figure 46-5: a new student is just a new tier. eval:route-table is run again with it included, and every category it now clears moves to it, as a reviewed config diff; a category it misses stays where it was.
function studentRoutes(): string
{
    $path = sys_get_temp_dir().'/student-routes-'.uniqid().'.php';

    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('moves the categories the student clears to it and leaves the ones it misses, from Figure 46-5\'s pass rates', function () {
    $rates = studentRoutes().'.json';

    File::put($rates, json_encode([
        'howto' => ['local' => 0.78, 'local-billing-ft' => 0.5, 'local-support-ft' => 0.971, 'mid' => 0.98, 'frontier' => 0.99],
        'plans' => ['local' => 0.75, 'local-billing-ft' => 0.5, 'local-support-ft' => 0.962, 'mid' => 0.97, 'frontier' => 0.99],
        'account' => ['local' => 0.97, 'local-billing-ft' => 0.5, 'local-support-ft' => 0.94, 'mid' => 0.98, 'frontier' => 0.99],
        'integrations' => ['local' => 0.7, 'local-billing-ft' => 0.5, 'local-support-ft' => 0.935, 'mid' => 0.97, 'frontier' => 0.99],
        'disputes' => ['local' => 0.6, 'local-billing-ft' => 0.5, 'local-support-ft' => 0.884, 'mid' => 0.9, 'frontier' => 0.98],
    ]));

    $out = studentRoutes();

    [$code, $output] = artisanOutput('eval:route-table', ['--from' => $rates, '--tiers' => 'local,local-billing-ft,local-support-ft,mid,frontier', '--out' => $out, '--margin' => 0.0]);

    $table = require $out;

    expect($code)->toBe(0)
        ->and($table['howto']['default']['start'])->toBe('local-support-ft')
        ->and($table['plans']['default']['start'])->toBe('local-support-ft')
        ->and($table['account']['default']['start'])->toBe('local')
        ->and($table['integrations']['default']['start'])->toBe('mid')
        ->and($table['disputes']['default']['start'])->toBe('frontier')
        ->and($table['howto']['default']['rates']['local-support-ft'])->toBe(0.971)
        ->and($table['_meta']['roles'])->toBe(['local', 'local-billing-ft', 'local-support-ft', 'mid', 'frontier']);
});

it('keeps the hand-written residency route when the table is written again with the student in it', function () {
    $rates = studentRoutes().'.json';

    File::put($rates, json_encode(['billing' => ['local' => 0.7, 'local-billing-ft' => 0.97, 'local-support-ft' => 0.75, 'mid' => 0.98, 'frontier' => 0.99]]));

    config(['ai-routes' => ['billing' => ['default' => ['start' => 'local'], 'residency' => ['start' => 'local-billing-ft', 'chain' => ['local-billing-ft', 'mid-in-region'], 'then' => 'person']]]]);

    $out = studentRoutes();

    artisanOutput('eval:route-table', ['--from' => $rates, '--tiers' => 'local,local-billing-ft,local-support-ft,mid,frontier', '--out' => $out, '--margin' => 0.0]);

    $table = require $out;

    expect($table['billing']['default']['start'])->toBe('local-billing-ft')
        ->and($table['billing']['residency']['chain'])->toBe(['local-billing-ft', 'mid-in-region'])
        ->and($table['billing']['residency']['then'])->toBe('person');
});
