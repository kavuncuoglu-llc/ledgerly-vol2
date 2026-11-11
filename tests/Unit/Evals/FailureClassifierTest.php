<?php

use App\Evals\CaseResult;
use App\Evals\EvalAnswer;
use App\Evals\FailureModes\Failure;
use App\Evals\FailureModes\FailureAnalysis;
use App\Evals\FailureModes\FailureBucket;
use App\Evals\FailureModes\FailureClassifier;
use App\Evals\FailureModes\FailureContext;
use App\Evals\Gates;
use App\Evals\GoldenCase;
use Illuminate\Support\Collection;

// Chapter 38, Topic 8: the order of the checks, one case per bucket.
function fmCase(array $override = []): GoldenCase
{
    return GoldenCase::fromArray($override + [
        'id' => 'billing-test-001',
        'category' => 'billing',
        'source' => 'human',
        'customer' => 'acme',
        'question' => 'Why did INV-4471 go up by $21.60?',
        'expected_behavior' => 'answer',
        'expected_chunks' => ['help:billing/late-fees#how-the-fee-is-calculated'],
        'must_include' => ['INV-4471', '21.60'],
    ]);
}

function fmResult(GoldenCase $case, string $answer, array $retrieved = ['help:billing/late-fees#how-the-fee-is-calculated'], string $behavior = 'answer', array $citations = ['help:billing/late-fees#how-the-fee-is-calculated']): CaseResult
{
    $evalAnswer = new EvalAnswer($answer, $behavior, $citations, $retrieved);

    return new CaseResult($case, $evalAnswer, Gates::evaluate($evalAnswer, $retrieved, $case));
}

function fmClassify(CaseResult $result, string $context = 'INV-4471 late fee 21.6', bool $usedTools = false): Failure
{
    return (new FailureClassifier)->classify($result, $context, $usedTools);
}

it('calls it a retrieval miss when the expected chunk was not retrieved', function () {
    $failure = fmClassify(fmResult(fmCase(), 'It is a late fee.', retrieved: ['help:billing/change-your-plan#upgrading'], citations: []));

    expect($failure->bucket)->toBe(FailureBucket::Retrieval);
});

it('calls it a coverage gap when the case expected a decline and the model answered', function () {
    $case = fmCase(['expected_behavior' => 'decline', 'expected_chunks' => [], 'must_include' => []]);

    $failure = fmClassify(fmResult($case, 'Yes, we integrate with every tool.', retrieved: [], citations: []));

    expect($failure->bucket)->toBe(FailureBucket::Coverage);
});

it('calls it behavior when the facts were in the context and the answer left one out', function () {
    $failure = fmClassify(fmResult(fmCase(), 'A late fee of $21.60 was added [late-fees].'));

    expect($failure->bucket)->toBe(FailureBucket::Behavior)
        ->and($failure->detail)->toBe(Failure::FACT_MISSING);
});

it('calls it a wrongful escalation when the sources answered and the model handed off', function () {
    $case = fmCase(['must_include' => []]);

    $failure = fmClassify(fmResult($case, 'I have opened a ticket for you.', behavior: 'escalate', citations: []));

    expect($failure->bucket)->toBe(FailureBucket::Behavior)
        ->and($failure->detail)->toBe(Failure::WRONGFUL_ESCALATION);
});

it('calls it wrong structure when only the citation or the format failed', function () {
    $case = fmCase(['must_include' => []]);

    $failure = fmClassify(fmResult($case, 'The late fee is 1.5% a month, prorated by day.', citations: []));

    expect($failure->bucket)->toBe(FailureBucket::Behavior)
        ->and($failure->detail)->toBe(Failure::WRONG_STRUCTURE);
});

it('calls it arithmetic when the number was not in the context and the answer computed another', function () {
    $case = fmCase(['must_include' => ['11.56']]);

    $failure = fmClassify(fmResult($case, 'The fee is $15.40 [late-fees].'), context: 'INV-4471 total 1284.5 days overdue 18', usedTools: true);

    expect($failure->bucket)->toBe(FailureBucket::Arithmetic);
});

it('calls it behavior, not arithmetic, when the number was in an account lookup the model never made', function () {
    $case = fmCase(['must_include' => ['11.56']]);

    $failure = fmClassify(fmResult($case, 'The fee is $15.40 [late-fees].'), context: 'the late fee passage', usedTools: false);

    expect($failure->bucket)->toBe(FailureBucket::Behavior)
        ->and($failure->detail)->toBe(Failure::TOOL_NOT_CALLED);
});

it('calls an invoice number the model never fetched behavior: it did not call the lookup', function () {
    $case = fmCase(['must_include' => ['INV-4438', 'INV-4471']]);

    $failure = fmClassify(fmResult($case, 'You have some overdue invoices [late-fees].'), context: 'the late fee passage', usedTools: false);

    expect($failure->bucket)->toBe(FailureBucket::Behavior)
        ->and($failure->detail)->toBe(Failure::TOOL_NOT_CALLED);
});

it('leaves a missing fact that was never in the context unclear', function () {
    $case = fmCase(['must_include' => ['Dana Whitfield']]);

    $failure = fmClassify(fmResult($case, 'The fee was added [late-fees].'), context: 'INV-4471');

    expect($failure->bucket)->toBe(FailureBucket::Unclear);
});

it('leaves a case that did not complete unclear', function () {
    $case = fmCase();

    $failure = fmClassify(new CaseResult($case, null, Gates::evaluate('', [], $case), error: 'RuntimeException: timeout'));

    expect($failure->bucket)->toBe(FailureBucket::Unclear)
        ->and($failure->reason)->toContain('timeout');
});

it('reads a case that passes as no failure at all', function () {
    $case = fmCase(['must_include' => []]);

    $passing = fmResult($case, 'The fee is 1.5% a month [late-fees].');

    $report = (new FailureAnalysis(new FailureClassifier, new FailureContext))->analyse(new Collection([$passing]));

    expect($report->total())->toBe(0);
});

it('counts the buckets, shares and sources the way Figure 38-8 prints them', function () {
    $production = fmCase(['id' => 'production-9', 'source' => 'production', 'must_include' => []]);

    $results = new Collection([
        fmResult(fmCase(), 'A late fee of $21.60 was added.'),
        fmResult($production, 'I opened a ticket.', behavior: 'escalate', citations: []),
        fmResult(fmCase(['id' => 'billing-test-002']), 'No idea.', retrieved: [], citations: []),
    ]);

    $report = (new FailureAnalysis(new FailureClassifier, new FailureContext))->analyse($results);

    expect($report->total())->toBe(3)
        ->and($report->bySource())->toBe(['golden' => 2, 'production' => 1])
        ->and($report->count(FailureBucket::Behavior))->toBe(2)
        ->and($report->count(FailureBucket::Unclear))->toBe(0)
        ->and($report->share(FailureBucket::Retrieval))->toBe(1 / 3)
        ->and($report->lines()[0])->toBe('3 failing cases (golden 2, production 1)')
        ->and(implode("\n", $report->lines()))->toContain('behavior and format')->toContain('fine-tuning (Section 7)')->toContain('wrongful escalation');
});
