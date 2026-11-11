<?php

use App\Help\HelpArticle;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config(['ledgerly.help_path' => resource_path('help')]);
});

it('ships the late fees article that Chapter 1 sends readers to', function () {
    $article = HelpArticle::find('billing', 'late-fees');

    expect($article)->not->toBeNull()
        ->and($article->body)->toContain('## How the fee is calculated')
        ->and($article->body)->toContain('## When a late fee is applied');
});

it('has valid front matter on every article, whatever their number', function () {
    $exitCode = Artisan::call('help:check');

    expect($exitCode)->toBe(0, Artisan::output());
});

it('serves every article page without an error', function () {
    $served = 0;

    foreach (HelpArticle::all() as $article) {
        $this->get($article->url())->assertOk();
        $served++;
    }

    expect($served)->toBe(HelpArticle::all()->count());
});

it('gives every article a unique category and slug', function () {
    $sources = HelpArticle::all()->map->source();

    expect($sources->unique()->count())->toBe($sources->count());
});
