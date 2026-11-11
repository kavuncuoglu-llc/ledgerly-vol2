<?php

use App\Ai\Context\ContextBlock;
use App\Ai\Retrieval\CitationChecker;

beforeEach(function () {
    $this->checker = new CitationChecker;

    $this->map = ContextBlock::labels([
        retrievedChunk('The fee is 1.5% of the outstanding balance per month, prorated by day. It is calculated on the invoice total excluding any previous late fees.', heading: 'Late fees › How the fee is calculated'),
        retrievedChunk('A late fee is applied automatically thirty days after the due date unless the invoice is disputed or a payment plan is active.', heading: 'Late fees › When a late fee is applied', overrides: ['id' => 2]),
    ]);
});

it('passes an answer whose sentences cite passages that say what the sentence says', function () {
    $report = $this->checker->check(
        'Ledgerly charges 1.5% of the outstanding balance per month, prorated by day [late-fees › How the fee is calculated]. It is applied automatically 30 days after the due date unless the invoice is disputed [late-fees › When a late fee is applied].',
        $this->map,
    );

    expect($report->hasErrors())->toBeFalse()
        ->and(array_keys($report->cited))->toBe(['late-fees › How the fee is calculated', 'late-fees › When a late fee is applied'])
        ->and($report->isGrounded())->toBeTrue();
});

it('flags a fabricated label', function () {
    $report = $this->checker->check('Fees are 1.5% a month [pricing › Plans].', $this->map);

    expect($report->errors)->toContain('Citation [pricing › Plans] does not match any provided passage.')
        ->and($report->cited)->toBe([]);
});

it('flags a factual sentence with no citation', function () {
    $report = $this->checker->check('Ledgerly charges 1.5% per month. You can ask us anything.', $this->map);

    expect($report->errors)->toHaveCount(1)
        ->and($report->errors[0])->toStartWith('Uncited factual sentence: “Ledgerly charges 1.5% per month.');
});

it('flags a sentence that cites a passage sharing none of its numbers (the book\'s 2 percent example)', function () {
    $report = $this->checker->check('Ledgerly charges 2% per month [late-fees › How the fee is calculated].', $this->map);

    expect($report->errors)->toBe(['Sentence cites [late-fees › How the fee is calculated] but shares no key terms or numbers with it.']);
});

it('reads a number written as a word as the same number', function () {
    $report = $this->checker->check('The fee starts after 30 days [late-fees › When a late fee is applied].', $this->map);

    expect($report->hasErrors())->toBeFalse();
});

it('accepts a citation that landed after the full stop', function () {
    $report = $this->checker->check('The fee is 1.5% per month. [late-fees › How the fee is calculated]', $this->map);

    expect($report->hasErrors())->toBeFalse();
});

it('falls back to shared key terms when the sentence has no numbers', function () {
    $good = $this->checker->check('The outstanding balance is prorated by day [late-fees › How the fee is calculated].', $this->map);
    $bad = $this->checker->check('Pandas eat bamboo happily [late-fees › How the fee is calculated].', $this->map);

    expect($good->hasErrors())->toBeFalse()
        ->and($bad->errors)->toHaveCount(1);
});

it('does not demand citations for a sentence that makes no factual claim', function () {
    $report = $this->checker->check('Happy to help. Let me know if that works.', $this->map);

    expect($report->hasErrors())->toBeFalse()
        ->and($report->isGrounded())->toBeFalse();
});

it('can check only the labels, for an answer built from a tool result', function () {
    $answer = 'INV-4471 is 18 days overdue with a fee of $21.60.';

    expect($this->checker->check($answer, $this->map)->hasErrors())->toBeTrue()
        ->and($this->checker->check($answer, $this->map, requireCitations: false)->hasErrors())->toBeFalse()
        ->and($this->checker->check($answer.' [made-up › Label]', $this->map, requireCitations: false)->hasErrors())->toBeTrue();
});
