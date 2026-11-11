<?php

use App\Ai\Context\ContextBlock;

it('renders the preamble, then each passage under its label, best first', function () {
    $block = ContextBlock::render([
        retrievedChunk('The fee is 1.5% of the outstanding balance per month, prorated by day.', heading: 'Late fees › How the fee is calculated'),
        retrievedChunk('A late fee is applied automatically 30 days after the due date.', heading: 'Late fees › When a late fee is applied', overrides: ['id' => 2]),
    ]);

    expect($block)->toMatchSnapshot();
});

it('drops the repeated heading line from the passage, because the label already says it', function () {
    $chunk = retrievedChunk('Body only.', heading: 'Late fees › How the fee is calculated');

    expect($chunk->content)->toStartWith("Late fees › How the fee is calculated\n")
        ->and($chunk->body())->toBe('Body only.');
});

it('tells the model that nothing was provided', function () {
    expect(ContextBlock::render([]))->toBe("Context:\n(no context provided)");
});

it('keeps two passages from one section distinguishable', function () {
    $labels = ContextBlock::labels([
        retrievedChunk('first half'),
        retrievedChunk('second half', overrides: ['id' => 2]),
    ]);

    expect(array_keys($labels))->toBe(['late-fees › How the fee is calculated', 'late-fees › How the fee is calculated #2']);
});

it('builds the citation url from the article and the heading anchor', function () {
    expect(retrievedChunk()->url())->toBe('/help/billing/late-fees#how-the-fee-is-calculated');
});
