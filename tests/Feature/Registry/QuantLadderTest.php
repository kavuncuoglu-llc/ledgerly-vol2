<?php

use App\Ai\Quantization\LadderBackend;
use App\Ai\Quantization\QuantLadder;
use Illuminate\Support\Facades\File;
use Tests\Support\RegistryWorld;

// Chapter 44, Topic 3 and Figure 44-3: the fine-tune and the base at each level, the advantage at each, and the smallest level that still clears the floor with a margin.
class FakeLadderBackend implements LadderBackend
{
    public array $loaded = [];

    public array $unloaded = [];

    public ?string $calibration = 'unset';

    // required facts per model name; the answer includes that share of the case's facts
    public function __construct(public array $facts, public array $sizes = []) {}

    public function quantize(string $f16, array $levels, ?string $calibration, string $directory): array
    {
        $this->calibration = $calibration;

        $built = [];

        foreach ($levels as $level) {
            $built[$level] = ['path' => "{$directory}/{$level}.gguf", 'bytes' => $this->sizes[$level] ?? 1000];
        }

        return $built;
    }

    public function load(string $name, string $gguf): void
    {
        $this->loaded[$name] = $gguf;
    }

    public function unload(string $name): void
    {
        $this->unloaded[] = $name;
    }

    public function chat(string $name, array $messages): array
    {
        $share = $this->facts[$name] ?? 1.0;

        return ['content' => $share >= 1.0 ? 'INV-1 125.84' : ($share > 0 ? 'INV-1' : 'no idea'), 'ttft_seconds' => 0.2, 'decode_tps' => 40.0];
    }
}

function ladderCases(): array
{
    return [
        ['id' => 'a', 'prompt' => [['role' => 'user', 'content' => 'q']], 'must_include' => ['INV-1', '125.84'], 'must_not_include' => ['INV-2']],
        ['id' => 'b', 'prompt' => [['role' => 'user', 'content' => 'q']], 'must_include' => ['INV-1', '125.84'], 'must_not_include' => []],
    ];
}

it('measures the fine-tune and the base at the same level and reports the advantage', function () {
    $backend = new FakeLadderBackend(['ladder-tuned-f16' => 1.0, 'ladder-base-f16' => 0.5, 'ladder-tuned-q4_k_m' => 1.0, 'ladder-base-q4_k_m' => 0.5], ['f16' => 16000, 'q4_k_m' => 4900]);

    $result = (new QuantLadder($backend))->run('/m/ft.f16.gguf', '/m/base.f16.gguf', ['f16', 'q4_k_m'], ladderCases(), '/c.txt', '/tmp/ladder', ['floor' => 0.95, 'margin' => 0.01, 'max_loss' => 0.01]);

    expect($result->levels['f16']['tuned']['required_facts'])->toBe(1.0)
        ->and($result->levels['f16']['base']['required_facts'])->toBe(0.5)
        ->and($result->advantage('f16'))->toBe(50.0)
        ->and($result->levels['q4_k_m']['tuned'])->toMatchArray(['golden_pass' => 1.0, 'ttft_seconds' => 0.2, 'decode_tps' => 40.0])
        ->and(array_keys($backend->loaded))->toContain('ladder-tuned-f16', 'ladder-base-q4_k_m')
        ->and($backend->unloaded)->toHaveCount(4)
        ->and($backend->calibration)->toBe('/c.txt');
});

it('picks the smallest level that clears the floor with a margin and keeps most of the advantage', function () {
    $backend = new FakeLadderBackend([
        'ladder-tuned-f16' => 1.0, 'ladder-base-f16' => 0.5,
        'ladder-tuned-q5_k_m' => 1.0, 'ladder-base-q5_k_m' => 0.5,
        'ladder-tuned-q4_k_m-imatrix' => 1.0, 'ladder-base-q4_k_m-imatrix' => 0.5,
        'ladder-tuned-q3_k_m' => 0.5, 'ladder-base-q3_k_m' => 0.5,
    ], ['f16' => 16000, 'q5_k_m' => 5700, 'q4_k_m+imatrix' => 4900, 'q3_k_m' => 3900]);

    $result = (new QuantLadder($backend))->run('/m/ft.f16.gguf', '/m/base.f16.gguf', ['f16', 'q5_k_m', 'q4_k_m+imatrix', 'q3_k_m'], ladderCases(), '/c.txt', '/tmp/ladder', ['floor' => 0.95, 'margin' => 0.01, 'max_loss' => 0.01]);

    expect($result->selected)->toBe('q4_k_m+imatrix')
        ->and($result->reason)->toContain('smallest level')
        ->and($result->levels['q3_k_m']['tuned']['golden_pass'])->toBe(0.0);           // the smallest file fails the floor, so it is not chosen
});

it('does not pick a level that clears the floor but gave up the advantage', function () {
    $backend = new FakeLadderBackend([
        'ladder-tuned-f16' => 1.0, 'ladder-base-f16' => 0.0,
        'ladder-tuned-q8_0' => 1.0, 'ladder-base-q8_0' => 1.0,
    ], ['f16' => 16000, 'q8_0' => 8500]);

    $result = (new QuantLadder($backend))->run('/m/ft.f16.gguf', '/m/base.f16.gguf', ['f16', 'q8_0'], ladderCases(), null, '/tmp/ladder', ['floor' => 0.95, 'margin' => 0.01, 'max_loss' => 0.01]);

    expect($result->selected)->toBe('f16');
});

it('says nothing is selected when no level clears the floor', function () {
    $backend = new FakeLadderBackend(['ladder-tuned-f16' => 0.5, 'ladder-tuned-q8_0' => 0.5]);

    $result = (new QuantLadder($backend))->run('/m/ft.f16.gguf', null, ['f16', 'q8_0'], ladderCases(), null, '/tmp/ladder', ['floor' => 0.95, 'margin' => 0.01, 'max_loss' => 0.01]);

    expect($result->selected)->toBeNull()->and($result->reason)->toContain('no level clears the floor of 0.95')
        ->and($result->advantage('f16'))->toBeNull();
});

it('refuses an empty slice', function () {
    expect(fn () => (new QuantLadder(new FakeLadderBackend([])))->run('/m/ft.f16.gguf', null, ['f16'], [], null, '/tmp', ['floor' => 0.95, 'margin' => 0.01, 'max_loss' => 0.01]))->toThrow(InvalidArgumentException::class, 'no cases');
});

it('runs from the command, prints the table and the chosen level, and keeps the ladder with the registry entry', function () {
    RegistryWorld::boot();

    $backend = new FakeLadderBackend([
        'ladder-tuned-f16' => 1.0, 'ladder-base-f16' => 0.5,
        'ladder-tuned-q4_k_m' => 1.0, 'ladder-base-q4_k_m' => 0.5,
    ], ['f16' => 16_000_000_000, 'q4_k_m' => 4_900_000_000]);

    app()->instance(LadderBackend::class, $backend);

    $entry = RegistryWorld::entry('billing', 1, 'candidate', ['model' => 'ledgerly-billing-ft']);

    File::put(dirname($entry->artifacts['files']['gguf']).'/billing-v1.f16.gguf', 'f16');

    $set = RegistryWorld::$directory.'/cases.jsonl';

    File::put($set, collect(ladderCases())->map(fn ($case) => json_encode($case))->implode("\n"));

    $this->artisan('eval:quant-ladder', ['model' => 'ledgerly-billing-ft', '--set' => $set, '--base-f16' => '/m/base.f16.gguf', '--levels' => 'f16,q4_k_m', '--limit' => 5, '--pick-smallest-passing' => true, '--record' => 'billing:1'])
        ->expectsOutputToContain('selected q4_k_m · advantage +50.0 points · 4.90 GB')
        ->expectsOutputToContain('ladder recorded with billing v1')
        ->assertExitCode(0);

    expect($entry->refresh()->artifacts['quantization_chosen'])->toBe('q4_k_m')
        ->and($entry->evaluations[0]['ladder']['selected'])->toBe('q4_k_m');

    File::delete(storage_path('eval/quant-ladder.json'));
    File::deleteDirectory(storage_path('app/quant-ladder'));

    RegistryWorld::clean();
});

it('needs a 16-bit GGUF and task text, and says what is missing', function () {
    app()->instance(LadderBackend::class, new FakeLadderBackend([]));

    $this->artisan('eval:quant-ladder', ['model' => 'nothing'])->expectsOutputToContain('No 16-bit GGUF')->assertExitCode(1);

    $this->artisan('eval:quant-ladder', ['model' => 'nothing', '--f16' => '/m/x.f16.gguf', '--set' => '/nowhere.jsonl'])->expectsOutputToContain('No cases at /nowhere.jsonl')->assertExitCode(1);

    $this->artisan('eval:quant-ladder', ['model' => 'nothing', '--f16' => '/m/x.f16.gguf'])->expectsOutputToContain('+imatrix needs task text')->assertExitCode(1);
});

it('explains that a base given as an Ollama tag cannot be re-quantized', function () {
    app()->instance(LadderBackend::class, new FakeLadderBackend([]));

    $this->artisan('eval:quant-ladder', ['model' => 'm', '--f16' => '/m/x.f16.gguf', '--base' => 'llama3.1:8b-instruct'])->expectsOutputToContain('is already quantized: name its 16-bit GGUF with --base-f16')->assertExitCode(1);
});
