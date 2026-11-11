<?php

use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Tokens\RatioCounter;
use App\Help\HelpArticle;

function chunkerFor(string $body, int $min = 200, int $max = 400, string $title = 'Understanding late fees'): array
{
    $article = HelpArticle::parse('billing', 'late-fees', helpArticleSource($title, 'billing', $body));

    return (new MarkdownChunker(new RatioCounter, $min, $max))->chunk($article);
}

function sentences(int $count): string
{
    return collect(range(1, $count))->map(fn (int $n) => "Sentence number {$n} explains one small part of the late fee rule.")->implode(' ');
}

it('splits on headings and keeps the title and heading at the top of every chunk', function () {
    $chunks = chunkerFor("Intro text.\n\n## How the fee is calculated\n\nThe fee is a percentage.\n\n## When a late fee is applied\n\nThirty days after the due date.\n", min: 1);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->heading)->toBe('Understanding late fees')
        ->and($chunks[1]->heading)->toBe('Understanding late fees › How the fee is calculated')
        ->and($chunks[1]->content)->toStartWith("Understanding late fees › How the fee is calculated\nThe fee is a percentage.")
        ->and($chunks[2]->heading)->toBe('Understanding late fees › When a late fee is applied')
        ->and(collect($chunks)->pluck('position')->all())->toBe([1, 2, 3])
        ->and($chunks[0]->source)->toBe('help/billing/late-fees.md');
});

it('nests an h3 under its h2 in the heading', function () {
    $chunks = chunkerFor("## Fees\n\nGeneral.\n\n### Waivers\n\nAsk support.\n", min: 1);

    expect(collect($chunks)->pluck('heading')->all())->toBe([
        'Understanding late fees › Fees',
        'Understanding late fees › Fees › Waivers',
    ]);
});

it('merges sections that are too small to mean anything on their own', function () {
    $chunks = chunkerFor("## One\n\nShort.\n\n## Two\n\nAlso short.\n");

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->heading)->toBe('Understanding late fees › One')
        ->and($chunks[0]->content)->toContain("Short.\n\nTwo\nAlso short.");
});

it('splits a section that is over the maximum on paragraph and sentence boundaries', function () {
    $paragraphs = collect(range(1, 6))->map(fn () => sentences(6))->implode("\n\n");

    $chunks = chunkerFor("## Long section\n\n{$paragraphs}\n");

    expect(count($chunks))->toBeGreaterThan(1)
        ->and(collect($chunks)->max('tokens'))->toBeLessThanOrEqual(400)
        ->and(collect($chunks)->every(fn ($chunk) => str_starts_with($chunk->content, 'Understanding late fees › Long section')))->toBeTrue();
});

it('repeats the last short paragraph at the start of the next chunk', function () {
    $paragraphs = collect(range(1, 6))->map(fn (int $n) => "Paragraph {$n} is short. ".str_repeat('Filler words here. ', 17))->implode("\n\n");

    $chunks = chunkerFor("## Long section\n\n{$paragraphs}\n", min: 1);

    expect(count($chunks))->toBeGreaterThan(1);

    $paragraphsOfFirst = explode("\n\n", $chunks[0]->content);
    $lastParagraphOfFirst = end($paragraphsOfFirst);

    expect($chunks[1]->content)->toContain($lastParagraphOfFirst);
});

it('splits a single paragraph that is bigger than the maximum on sentences', function () {
    $chunks = chunkerFor("## Wall of text\n\n".sentences(60)."\n");

    expect(count($chunks))->toBeGreaterThan(1)
        ->and(collect($chunks)->max('tokens'))->toBeLessThanOrEqual(400);
});

it('does not treat a hash inside a code fence as a heading', function () {
    $chunks = chunkerFor("## Example\n\n```bash\n## not a heading\nphp artisan help:check\n```\n", min: 1);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->content)->toContain('## not a heading');
});

it('skips a heading that has no body of its own', function () {
    $chunks = chunkerFor("## Parent\n\n### Child\n\nBody of the child.\n", min: 1);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->heading)->toBe('Understanding late fees › Parent › Child');
});

it('counts the tokens of the whole chunk, heading included', function () {
    $chunks = chunkerFor("## Fees\n\nThe fee is 1.5 percent per month.\n", min: 1);

    expect($chunks[0]->tokens)->toBe((new RatioCounter)->count($chunks[0]->content));
});

it('gives an article with no body no chunks', function () {
    expect(chunkerFor("\n"))->toBe([]);
});
