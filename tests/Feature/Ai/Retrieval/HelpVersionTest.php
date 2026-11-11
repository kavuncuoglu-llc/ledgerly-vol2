<?php

use App\Ai\Cache\HelpVersion;
use App\Models\Tenant;

beforeEach(function () {
    config(['ledgerly.help_version' => null]);

    helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing')]);
});

it('uses LEDGERLY_HELP_VERSION above everything else', function () {
    chunkRow(['help_version' => 'ingested']);

    config(['ledgerly.help_version' => 'pinned-by-env']);

    expect((new HelpVersion)->current())->toBe('pinned-by-env');
});

it('reads the version help:ingest stamped on the shared chunks', function () {
    chunkRow(['help_version' => 'abc1234']);
    chunkRow(['help_version' => 'private-one', 'tenant_id' => Tenant::factory()->create()->id]);

    expect((new HelpVersion)->current())->toBe('abc1234');
});

it('falls back to a hash of the articles when nothing is ingested, and the hash changes with the text', function () {
    $first = (new HelpVersion)->current();

    helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "Different text.\n")]);

    expect($first)->toHaveLength(12)
        ->and((new HelpVersion)->current())->not->toBe($first);
});

it('forgets what it resolved when told to', function () {
    $versions = new HelpVersion;

    $fallback = $versions->current();

    chunkRow(['help_version' => 'now-ingested']);

    expect($versions->current())->toBe($fallback);

    $versions->forget();

    expect($versions->current())->toBe('now-ingested');
});
