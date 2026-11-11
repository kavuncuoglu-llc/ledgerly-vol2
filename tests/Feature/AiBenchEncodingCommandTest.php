<?php

use App\Ai\Encoding\BenchPrompt;
use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

afterEach(function () {
    foreach (glob(storage_path('benchmarks/20*.json')) as $file) {
        File::delete($file);
    }
});

it('ships a fixture of 120 invoices in one currency and 20 questions for the first 50', function () {
    $fixture = json_decode(File::get(base_path('storage/benchmarks/invoices.json')), true);
    $questions = json_decode(File::get(base_path('storage/benchmarks/invoice-questions.json')), true);

    expect($fixture)->toHaveCount(120)
        ->and(collect($fixture)->pluck('currency')->unique()->all())->toBe(['USD'])
        ->and(array_keys($fixture[0]))->toBe(['id', 'customer_id', 'number', 'status', 'currency', 'subtotal', 'tax', 'total', 'issued_at', 'due_at', 'paid_at', 'late_fee_cents', 'notes', 'created_at', 'updated_at', 'deleted_at'])
        ->and($questions['rows'])->toBe(50)
        ->and($questions['questions'])->toHaveCount(20);
});

it('counts tokens for the six encodings without a model', function () {
    Http::fake();

    $this->artisan('ai:bench-encoding', ['--tokens-only' => true, '--no-save' => true])
        ->expectsOutputToContain('json-pretty')
        ->expectsOutputToContain('json-compact')
        ->expectsOutputToContain('yaml')
        ->expectsOutputToContain('markdown-table')
        ->expectsOutputToContain('tsv')
        ->expectsOutputToContain('toon')
        ->expectsOutputToContain('toJson() of the same 50 models, all 16 columns')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('puts every compact format under compact JSON and compact JSON under pretty JSON', function () {
    Artisan::call('ai:bench-encoding', ['--tokens-only' => true, '--no-save' => true]);

    $lines = collect(explode("\n", Artisan::output()))
        ->mapWithKeys(function (string $line) {
            preg_match('/^(\S+)\s+([\d,]+)\s/', $line, $m);

            return $m === []
                ? []
                : [$m[1] => (int) str_replace(',', '', $m[2])];
        });

    expect($lines['toon'])->toBeLessThan($lines['json-compact'])
        ->and($lines['tsv'])->toBeLessThan($lines['json-compact'])
        ->and($lines['markdown-table'])->toBeLessThan($lines['json-compact'])
        ->and($lines['json-compact'])->toBeLessThan($lines['json-pretty']);
});

it('asks each question of each encoding with the deterministic profile and scores the answers', function () {
    $questions = json_decode(File::get(base_path('storage/benchmarks/invoice-questions.json')), true)['questions'];

    $expected = array_column($questions, 'expected');

    $call = 0;

    Http::fake(['*/chat/completions' => function () use (&$call, $expected) {
        $answer = $expected[$call % 20];

        $call++;

        return Http::response(chatBody($call > 100 ? 'I am not sure.' : "The answer is {$answer}."));
    }]);

    $this->artisan('ai:bench-encoding', ['--model' => 'llama3.1:8b', '--no-save' => true])
        ->expectsOutputToContain('comprehension (20 q)')
        ->expectsOutputToContain('20 / 20')
        ->assertSuccessful();

    $bodies = sentChatBodies();

    expect($bodies)->toHaveCount(120)
        ->and($bodies[0]['temperature'])->toBe(0)
        ->and($bodies[0]['max_tokens'])->toBe(20)
        ->and($bodies[0]['model'])->toBe('llama3.1:8b')
        ->and(ModelCall::where('feature', 'bench.encoding')->count())->toBe(120);
});

it('writes the results by model and date', function () {
    Http::fake(['*/chat/completions' => Http::response(chatBody('INV-0'))]);

    $this->artisan('ai:bench-encoding', ['--model' => 'llama3.1:8b'])->assertSuccessful();

    $path = storage_path('benchmarks/'.now()->toDateString().'-llama3.1-8b.json');

    expect(File::exists($path))->toBeTrue();

    $saved = json_decode(File::get($path), true);

    expect($saved['model'])->toBe('llama3.1:8b')
        ->and($saved['rows'])->toBe(50)
        ->and(array_keys($saved['results']))->toBe(['json-pretty', 'json-compact', 'yaml', 'markdown-table', 'tsv', 'toon']);
});

it('skips comprehension when the rows differ from the ones the answers were written for', function () {
    Http::fake();

    $this->artisan('ai:bench-encoding', ['--rows' => 20, '--no-save' => true])
        ->expectsOutputToContain('comprehension is skipped at --rows=20')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('says so when the model server is down', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    Sleep::fake();

    $this->artisan('ai:bench-encoding', ['--no-save' => true])
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();
});

describe('BenchPrompt::matches', function () {
    it('finds the expected value in a chatty answer', function (string $answer, string $expected, bool $matches) {
        expect(BenchPrompt::matches($answer, $expected))->toBe($matches);
    })->with([
        ['INV-4508', 'INV-4508', true],
        ['It is inv-4508.', 'INV-4508', true],
        ['INV-4509', 'INV-4508', false],
        ['14', '14', true],
        ['There are 14 overdue invoices', '14', true],
        ['There are 114 overdue invoices', '14', false],
        ['INV-4514 is paid', '14', false],
        ['$1,284.50', '1284.5', true],
        ['1284.5', '1284.50', true],
        ['1284.55', '1284.5', false],
        ['2026-04-02', '2026-04-02', true],
        ['Due 2026-04-03', '2026-04-02', false],
        ['Overdue.', 'overdue', true],
    ]);
});
