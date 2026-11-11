<?php

use App\Help\HelpArticle;

it('parses front matter and body', function () {
    $article = HelpArticle::parse('billing', 'late-fees', helpArticleSource('Understanding late fees', 'billing'));

    expect($article->title)->toBe('Understanding late fees')
        ->and($article->category)->toBe('billing')
        ->and($article->slug)->toBe('late-fees')
        ->and($article->summary)->toBe('A short summary.')
        ->and($article->updated->toDateString())->toBe('2025-10-02')
        ->and($article->body)->toStartWith('Body text.')
        ->and($article->body)->not->toContain('---')
        ->and($article->source())->toBe('help/billing/late-fees.md')
        ->and($article->missingFrontMatterKeys())->toBe([])
        ->and($article->declaresMatchingCategory())->toBeTrue();
});

it('falls back to the slug when front matter is missing', function () {
    $article = HelpArticle::parse('billing', 'late-fees', "Just a body.\n");

    expect($article->title)->toBe('Late Fees')
        ->and($article->summary)->toBe('')
        ->and($article->body)->toBe("Just a body.\n")
        ->and($article->missingFrontMatterKeys())->toBe(['title', 'category', 'summary', 'updated']);
});

it('survives front matter that is not valid yaml', function () {
    $article = HelpArticle::parse('billing', 'broken', "---\ntitle: Broken: really\n---\n\nBody.\n");

    expect($article->title)->toBe('Broken')
        ->and($article->body)->toBe("Body.\n");
});

it('reads an empty or missing help directory as no articles', function () {
    config(['ledgerly.help_path' => sys_get_temp_dir().'/ledgerly-no-such-help-dir']);

    expect(HelpArticle::all())->toBeEmpty()
        ->and(HelpArticle::categories())->toBeEmpty()
        ->and(HelpArticle::inCategory('billing'))->toBeEmpty()
        ->and(HelpArticle::find('billing', 'late-fees'))->toBeNull();

    helpLibrary([]);

    expect(HelpArticle::all())->toBeEmpty();
});

it('discovers any category directory that exists', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing'),
        'getting-started/first-invoice.md' => helpArticleSource('Your first invoice', 'getting-started'),
        'zz-future-topic/anything.md' => helpArticleSource('Anything', 'zz-future-topic'),
        'empty-category/.gitkeep' => '',
    ]);

    expect(HelpArticle::categories()->pluck('slug')->all())->toBe(['billing', 'getting-started', 'zz-future-topic'])
        ->and(HelpArticle::categoryLabel('getting-started'))->toBe('Getting Started')
        ->and(HelpArticle::all())->toHaveCount(3)
        ->and(HelpArticle::find('getting-started', 'first-invoice')?->title)->toBe('Your first invoice');
});

it('ignores underscore files and other file types', function () {
    helpLibrary([
        'billing/_notes.md' => 'draft',
        'billing/readme.txt' => 'not markdown',
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing'),
    ]);

    expect(HelpArticle::inCategory('billing')->pluck('slug')->all())->toBe(['late-fees']);
});

it('refuses path traversal and odd slugs', function () {
    helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing')]);

    expect(HelpArticle::find('..', 'late-fees'))->toBeNull()
        ->and(HelpArticle::find('billing', '../billing/late-fees'))->toBeNull()
        ->and(HelpArticle::find('billing', 'Late Fees'))->toBeNull()
        ->and(HelpArticle::inCategory('../'))->toBeEmpty();
});

it('renders markdown with tables and heading anchors that match the headings', function () {
    $body = "Intro.\n\n## How the fee is calculated\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\n### Worked example\n\nText.\n";
    $article = HelpArticle::parse('billing', 'late-fees', helpArticleSource('Late fees', 'billing', $body));

    expect($article->html())
        ->toContain('<table>')
        ->toContain('id="how-the-fee-is-calculated"')
        ->toContain('href="#how-the-fee-is-calculated"')
        ->and($article->headings())->toBe([
            ['level' => 2, 'text' => 'How the fee is calculated', 'id' => 'how-the-fee-is-calculated'],
            ['level' => 3, 'text' => 'Worked example', 'id' => 'worked-example'],
        ]);
});

it('strips raw html from article bodies', function () {
    $article = HelpArticle::parse('billing', 'x', helpArticleSource('X', 'billing', "Hello <script>alert(1)</script> world\n"));

    expect($article->html())->not->toContain('<script>');
});

it('does not read headings inside code fences', function () {
    $body = "## Real\n\n```\n## Not a heading\n```\n";
    $article = HelpArticle::parse('billing', 'x', helpArticleSource('X', 'billing', $body));

    expect(array_column($article->headings(), 'text'))->toBe(['Real']);
});
