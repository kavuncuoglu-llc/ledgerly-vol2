<?php

use App\Evals\Generators\BillingSftExamples;
use App\Evals\GoldenSet;
use Illuminate\Support\Facades\File;

// Chapter 39, Topic 9: the fixture the first LoRA run trains on is generated from the seeded database, so the committed file can be checked against the database it came from.
beforeEach(function () {
    $this->seed();

    $this->dir = sys_get_temp_dir().'/dataset-fixture-'.bin2hex(random_bytes(4));
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('writes 200 prompt-and-completion rows and 20 held-out cases', function () {
    $this->artisan('dataset:fixture', ['--out' => $this->dir.'/rows.jsonl', '--golden' => $this->dir.'/holdout.jsonl'])->assertExitCode(0);

    $rows = collect(file($this->dir.'/rows.jsonl', FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

    expect($rows)->toHaveCount(200)
        ->and($rows->every(fn ($row) => array_keys($row) === ['prompt', 'completion']))->toBeTrue()
        ->and($rows->every(fn ($row) => $row['prompt'][0]['content'] === BillingSftExamples::SYSTEM))->toBeTrue()
        ->and(file($this->dir.'/holdout.jsonl'))->toHaveCount(20);
});

it('is the committed fixture, byte for byte, so a change to the generator shows in the diff', function () {
    $this->artisan('dataset:fixture', ['--out' => $this->dir.'/rows.jsonl', '--golden' => $this->dir.'/holdout.jsonl'])->assertExitCode(0);

    expect(file_get_contents($this->dir.'/rows.jsonl'))->toBe(file_get_contents(base_path('training/data/fixtures/billing_sft_small.jsonl')))
        ->and(file_get_contents($this->dir.'/holdout.jsonl'))->toBe(file_get_contents(base_path('tests/golden/billing_holdout.jsonl')));
});

it('never trains on an invoice it holds out', function () {
    $examples = new BillingSftExamples;

    $held = collect($examples->heldOut())->pluck('must_include.0');

    $training = collect($examples->rows(200))->map(fn ($row) => $row['prompt'][1]['content'])->implode("\n");

    expect($held)->toHaveCount(20);

    foreach ($held as $number) {
        expect($training)->not->toContain($number);
    }
});

it('states in every late-fee completion the invoice number and the fee the database holds', function () {
    foreach ((new BillingSftExamples)->heldOut() as $case) {
        [$number, $fee] = $case['must_include'];

        expect($case['reference_answer'])->toContain($number)->toContain('$'.$fee);
    }
});

it('puts the held-out cases in the golden set as valid billing cases', function () {
    $cases = GoldenSet::default()->all()->filter(fn ($case) => str_starts_with($case->id, 'synthetic-holdout-'));

    expect($cases)->toHaveCount(20)
        ->and($cases->every(fn ($case) => $case->category === 'billing' && $case->mustNotInclude !== []))->toBeTrue();
});
