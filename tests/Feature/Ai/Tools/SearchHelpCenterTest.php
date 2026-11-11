<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Models\HelpChunk;

function chunk(string $source, int $position, string $heading, string $content): void
{
    HelpChunk::create([
        'source' => $source,
        'position' => $position,
        'heading' => $heading,
        'content' => $content,
        'tokens' => 40,
        'embedding' => fakeEmbeddingVector($content),
        'title' => 'Help',
        'heading_path' => 'Help › '.$heading,
        'help_version' => 'v1',
        'chunk_key' => sha1($source.$position),
    ]);
}

it('returns the three best help passages with their sources', function () {
    fakeEmbeddings();

    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

    chunk('help/billing/late-fees.md', 1, 'Late fees › When a late fee is applied', 'A late fee is applied after the thirty day grace period passes.');
    chunk('help/billing/late-fees.md', 2, 'Late fees › How the fee is calculated', 'The late fee is a small percentage of the balance for each day late.');
    chunk('help/invoices/statuses.md', 1, 'Statuses', 'Invoices move from draft to sent to paid.');
    chunk('help/team/roles.md', 1, 'Roles', 'Owners can invite team members and change roles.');
    chunk('help/exports/csv.md', 1, 'CSV', 'Export your customer list as a CSV file from the customers page.');

    [$user] = customerWithInvoice();

    $result = app(ToolRunner::class)->executeOne(
        app(ToolRegistry::class)->native()->get('search_help_center'),
        ['query' => 'when is a late fee applied after the grace period'],
        new ToolContext($user, source: 'mcp', toolCallId: 'x'),
    );

    $passages = $result->toArray()['passages'];

    expect($passages)->toHaveCount(3)
        ->and($passages[0]['source'])->toBe('help/billing/late-fees.md')
        ->and($passages[0]['heading'])->toBe('Late fees › When a late fee is applied')
        ->and($passages[0]['similarity'])->toBeGreaterThan($passages[2]['similarity'])
        ->and($passages[0]['content'])->toContain('grace period');
});

it('rejects a query that is too short', function () {
    [$user] = customerWithInvoice();

    $result = app(ToolRunner::class)->executeOne(
        app(ToolRegistry::class)->native()->get('search_help_center'),
        ['query' => 'a'],
        new ToolContext($user, toolCallId: 'x'),
    );

    expect($result->error)->toBe('invalid arguments');
});

it('is not offered to the assistant before Chapter 15', function () {
    expect(app(ToolRegistry::class)->get('search_help_center'))->toBeNull();
});
