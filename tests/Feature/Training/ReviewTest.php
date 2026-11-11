<?php

use App\Ai\Training\Dataset;
use App\Facades\Ai;
use Illuminate\Support\Facades\File;
use Tests\Support\TinyDataset;

// Chapter 40, Topic 6: accept, edit or reject, with reasons, a second reviewer on a tenth, and a judge that sorts and never labels.
beforeEach(function () {
    TinyDataset::use();

    $this->dataset = TinyDataset::exported('billing-t1', [
        ['conversation' => 1, 'question' => 'Why is there a late fee on INV-1000?'],
        ['conversation' => 2, 'question' => 'What do I owe on INV-1001?'],
        ['conversation' => 3, 'question' => 'Is INV-1002 paid?', 'reply' => 'I have refunded the fee.'],
        ['conversation' => 4, 'question' => 'Late fee on INV-1003?', 'reply' => 'This invoice has a fee.'],
        ['conversation' => 5, 'question' => 'Balance of INV-1004?'],
    ]);

    $this->file = sys_get_temp_dir().'/review-'.bin2hex(random_bytes(4)).'.json';
});

afterEach(function () {
    TinyDataset::cleanUp();

    File::delete($this->file);
});

function trReviewFile(string $path, array $decisions, array $second = []): void
{
    File::put($path, json_encode(['reviewers' => ['lead', 'agent'], 'decisions' => $decisions, 'second_reviews' => $second]));
}

it('accepts what the file does not mention, edits what it edits and drops what it rejects', function () {
    trReviewFile($this->file, [
        ['conversation' => 3, 'turn' => 5, 'action' => 'reject', 'reason' => 'promise not allowed'],
        ['conversation' => 4, 'turn' => 5, 'action' => 'edit', 'completion' => 'Invoice INV-1003 has a late fee of $4.00.'],
    ]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])
        ->expectsOutputToContain('reviewed 5 · accepted 3 · edited 1 · rejected 1')
        ->expectsOutputToContain('promise not allowed 1')
        ->assertExitCode(0);

    $reviewed = $this->dataset->read('reviewed.jsonl');

    expect($reviewed)->toHaveCount(4)
        ->and(collect($reviewed)->pluck('id')->all())->not->toContain('ex2')
        ->and(collect($reviewed)->firstWhere('id', 'ex3')['completion'][0]['content'])->toBe('Invoice INV-1003 has a late fee of $4.00.')
        ->and(collect($reviewed)->firstWhere('id', 'ex3')['review']['action'])->toBe('edit')
        ->and($this->dataset->readJson('review.json')['rejection_reasons'])->toBe(['promise not allowed' => 1]);
});

it('reports agreement between two reviewers on the examples both saw', function () {
    trReviewFile($this->file, [['conversation' => 3, 'turn' => 5, 'action' => 'reject', 'reason' => 'promise not allowed']], [
        ['conversation' => 1, 'turn' => 5, 'action' => 'accept'],
        ['conversation' => 3, 'turn' => 5, 'action' => 'edit'],
    ]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])
        ->expectsOutputToContain('double-reviewed 2 · agreement 50%')
        ->assertExitCode(0);
});

it('refuses an edit that brings personal data back, and says so', function () {
    trReviewFile($this->file, [['conversation' => 4, 'turn' => 5, 'action' => 'edit', 'completion' => 'Write to dana@acme.example about INV-1003.']]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])
        ->expectsOutputToContain('contains personal data: refused')
        ->expectsOutputToContain('edited 0')
        ->assertExitCode(0);
});

it('warns about a decision for a conversation that is not in the dataset', function () {
    trReviewFile($this->file, [['conversation' => 99, 'turn' => 5, 'action' => 'reject', 'reason' => 'other']]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])
        ->expectsOutputToContain('is not in the dataset')
        ->expectsOutputToContain('rejected 0')
        ->assertExitCode(0);
});

it('accepts a conversation by its hash as well as by its id', function () {
    trReviewFile($this->file, [['conversation' => TinyDataset::hash(3), 'turn' => 5, 'action' => 'reject', 'reason' => 'other']]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])->expectsOutputToContain('rejected 1')->assertExitCode(0);
});

it('refuses an action that is not accept, edit or reject, and an edit with no text', function () {
    trReviewFile($this->file, [['conversation' => 1, 'turn' => 5, 'action' => 'maybe'], ['conversation' => 2, 'turn' => 5, 'action' => 'edit', 'completion' => '  ']]);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--apply' => $this->file])
        ->expectsOutputToContain('action must be accept, edit or reject')
        ->expectsOutputToContain('has no completion')
        ->assertExitCode(0);

    expect($this->dataset->read('reviewed.jsonl'))->toHaveCount(5);
});

it('opens the queue, and sorts it by the judge\'s doubt without ever labelling', function () {
    Ai::fake()->for('dataset.triage')->respondWith('5', times: 5);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--triage' => 'judge', '--limit' => 2])
        ->expectsOutputToContain('5 examples · queue sorted by judge doubt (judge scores never become labels)')
        ->assertExitCode(0);

    $queue = $this->dataset->read('queue.jsonl');

    expect($queue)->toHaveCount(5)
        ->and($queue[0]['doubt'])->toBe(1)
        ->and($this->dataset->exists('reviewed.jsonl'))->toBeFalse();
});

it('treats a judge that cannot answer as more doubt, not less', function () {
    Ai::fake()->for('dataset.triage')->respondWith('no idea', times: 5);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--triage' => 'judge'])->assertExitCode(0);

    expect($this->dataset->read('queue.jsonl')[0]['doubt'])->toBe(5);
});

it('refuses a dataset that does not exist, and a triage it does not know', function () {
    $this->artisan('dataset:review', ['dataset' => 'billing-nothing'])->expectsOutputToContain('has no train.jsonl')->assertExitCode(1);

    $this->artisan('dataset:review', ['dataset' => 'billing-t1', '--triage' => 'vibes'])->expectsOutputToContain('--triage is none or judge')->assertExitCode(1);

    $this->artisan('dataset:review', ['dataset' => '../etc'])->expectsOutputToContain('is not a dataset name')->assertExitCode(1);
});

it('keeps rows and metadata together: a dataset whose files disagree is refused', function () {
    File::put($this->dataset->path('manifest.jsonl'), '');

    expect(fn () => Dataset::named('billing-t1')->joined())->toThrow(InvalidArgumentException::class);
});
