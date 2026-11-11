<?php

use Illuminate\Support\Facades\Artisan;

it('passes when every article has complete front matter', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing'),
        'account/profile.md' => helpArticleSource('Profile', 'account'),
    ]);

    $this->artisan('help:check')
        ->expectsOutputToContain('2 articles in 2 categories')
        ->assertExitCode(0);
});

it('passes on an empty library', function () {
    helpLibrary([]);

    $this->artisan('help:check')->assertExitCode(0);
});

it('fails on missing front matter keys', function () {
    helpLibrary([
        'billing/late-fees.md' => "---\ntitle: Late fees\ncategory: billing\n---\n\nBody.\n",
    ]);

    $this->artisan('help:check')
        ->expectsOutputToContain("front matter is missing 'summary'")
        ->assertExitCode(1);
});

it('fails when the category does not match the directory', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'invoices'),
    ]);

    expect(Artisan::call('help:check'))->toBe(1)
        ->and(Artisan::output())->toContain('does not match its directory');
});

it('fails when the body repeats an h1', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "# Late fees\n\nBody.\n"),
    ]);

    expect(Artisan::call('help:check'))->toBe(1)
        ->and(Artisan::output())->toContain('repeats an H1');
});
