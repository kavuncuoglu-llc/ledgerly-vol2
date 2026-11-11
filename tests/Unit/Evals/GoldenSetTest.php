<?php

use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use Illuminate\Support\Facades\File;

// Chapter 29, Topic 4: the committed golden set is a test fixture of its own.
it('holds 50 to 200 cases, each with a known source', function () {
    $cases = GoldenSet::default()->all();

    expect($cases->count())->toBeBetween(50, 200)
        ->and($cases->pluck('source')->unique()->sort()->values()->all())->toBe(['human', 'synthetic'])
        ->and($cases->pluck('id')->duplicates())->toBeEmpty();
});

it('is balanced like the table in Figure 29-4: mostly answers, a few declines, escalations and refusals', function () {
    $human = GoldenSet::default()->all('human');

    $share = fn (string $behavior) => $human->where('expectedBehavior', $behavior)->count() / $human->count();

    expect($share('answer'))->toBeBetween(0.65, 0.85)
        ->and($share('decline'))->toBeBetween(0.05, 0.15)
        ->and($share('escalate'))->toBeBetween(0.05, 0.15)
        ->and($share('refuse'))->toBeBetween(0.03, 0.10);
});

it('names help-center chunks that exist, in the help:article#section form', function () {
    $missing = GoldenSet::default()->all()
        ->flatMap(fn (GoldenCase $case) => $case->expectedChunks)
        ->unique()
        ->reject(function (string $label) {
            preg_match('/^help:([a-z0-9\-\/]+)#[a-z0-9\-]+$/', $label, $m);

            return isset($m[1]) && File::exists(base_path("resources/help/{$m[1]}.md"));
        });

    expect($missing->values()->all())->toBe([]);
});

it('says what must be true, never the exact words: every answer case has facts or a chunk to check', function () {
    $empty = GoldenSet::default()->all()
        ->where('expectedBehavior', 'answer')
        ->filter(fn (GoldenCase $case) => $case->expectedChunks === [] && $case->mustInclude === []);

    expect($empty->pluck('id')->all())->toBe([]);
});

it('keeps another customer\'s invoice numbers out of data cases through must_not_include', function () {
    $withData = GoldenSet::default()->all()->filter(fn (GoldenCase $case) => $case->mustInclude !== [] && str_starts_with($case->mustInclude[0], 'INV-'));

    expect($withData)->not->toBeEmpty();

    foreach ($withData as $case) {
        expect($case->mustNotInclude)->not->toBeEmpty()
            ->and(array_intersect($case->mustInclude, $case->mustNotInclude))->toBe([]);
    }
});

it('picks the same smoke subset every time, of the requested size, covering every behavior', function () {
    $first = GoldenSet::default()->smoke(25)->pluck('id')->all();

    $second = GoldenSet::default()->smoke(25)->pluck('id')->all();

    expect($first)->toBe($second)
        ->and($first)->toHaveCount(25)
        ->and(GoldenSet::default()->smoke(25)->pluck('expectedBehavior')->unique()->sort()->values()->all())->toBe(['answer', 'decline', 'escalate', 'refuse']);
});

it('rejects a duplicate id, a bad source and a bad behavior', function () {
    $dir = sys_get_temp_dir().'/golden-'.uniqid();

    File::ensureDirectoryExists($dir);

    $row = ['id' => 'a', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'q', 'expected_behavior' => 'answer'];

    File::put("$dir/one.jsonl", json_encode($row)."\n".json_encode($row)."\n");

    expect(fn () => (new GoldenSet($dir))->all())->toThrow(InvalidArgumentException::class, 'Duplicate golden case ids: a');

    File::put("$dir/one.jsonl", json_encode(['source' => 'made-up'] + $row)."\n");

    expect(fn () => (new GoldenSet($dir))->all())->toThrow(InvalidArgumentException::class, 'source must be one of');

    File::put("$dir/one.jsonl", json_encode(['expected_behavior' => 'shrug'] + $row)."\n");

    expect(fn () => (new GoldenSet($dir))->all())->toThrow(InvalidArgumentException::class, 'expected_behavior must be one of');

    File::deleteDirectory($dir);
});

it('round trips a case through JSON lines', function () {
    $case = new GoldenCase('x-1', 'billing', 'synthetic', 'acme', 'Why?', 'answer', ['help:a/b#c'], ['INV-1'], ['INV-2'], 'Because.', generator: 'late-invoices@1 via local');

    expect(GoldenCase::fromArray(json_decode($case->toJson(), true)))->toEqual($case);
});
