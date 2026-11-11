<?php

use App\Ai\Streaming\DeltaBatcher;

it('flushes after twenty tokens however fast they arrive', function () {
    $frames = [];

    $batcher = new DeltaBatcher(function (string $text) use (&$frames) {
        $frames[] = $text;
    }, maxMs: 60_000);

    foreach (range(1, 45) as $i) {
        $batcher->push("t{$i} ");
    }

    expect($frames)->toHaveCount(2)
        ->and(substr_count($frames[0], 't'))->toBe(20);

    $batcher->flushNow();

    expect($frames)->toHaveCount(3)
        ->and(implode('', $frames))->toBe(implode('', array_map(fn (int $i) => "t{$i} ", range(1, 45))));
});

it('flushes once the time window has passed even with few tokens', function () {
    $frames = [];

    $batcher = new DeltaBatcher(function (string $text) use (&$frames) {
        $frames[] = $text;
    }, maxMs: 10);

    $batcher->push('Hello');

    expect($frames)->toBe([]);

    usleep(15_000);

    $batcher->push(' there');

    expect($frames)->toBe(['Hello there']);
});

it('sends nothing when there is nothing buffered', function () {
    $frames = [];

    $batcher = new DeltaBatcher(function (string $text) use (&$frames) {
        $frames[] = $text;
    });

    $batcher->flushNow();

    expect($frames)->toBe([]);
});

it('keeps the order of the text across flushes', function () {
    $sent = '';

    $batcher = new DeltaBatcher(function (string $text) use (&$sent) {
        $sent .= $text;
    }, maxMs: 60_000, maxTokens: 3);

    foreach (str_split('The late fee starts after thirty days.', 4) as $piece) {
        $batcher->push($piece);
    }

    $batcher->flushNow();

    expect($sent)->toBe('The late fee starts after thirty days.');
});
