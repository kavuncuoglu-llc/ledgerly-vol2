<?php

use App\Ai\Contract\ContractResult;
use App\Ai\Contract\ContractSuite;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\RateLimited;
use Illuminate\Support\Facades\Artisan;

// Chapter 47, Topic 8: one suite, every driver. A driver that fails a line cannot be deployed; a known gap is a line the package cannot pass, written down with the reason.

function contractFailures(array $results): array
{
    return collect($results)->reject(fn (ContractResult $r) => $r->passed)->map(fn (ContractResult $r) => "{$r->driver} {$r->wire} {$r->check}: {$r->detail}")->values()->all();
}

it('passes the contract suite', function (string $driver, string $wire) {
    $results = (new ContractSuite)->run($driver, $wire);

    expect(contractFailures($results))->toBe([])
        ->and($results)->not->toBeEmpty();
})->with(fn () => collect(ContractSuite::DRIVERS)->flatMap(fn (string $driver) => collect(ContractSuite::WIRES)
    ->filter(fn (string $wire) => (new ContractSuite)->applies($driver, $wire))
    ->mapWithKeys(fn (string $wire) => ["{$driver} on {$wire}" => [$driver, $wire]]))->all());

it('does not run Prism on the OpenAI-compatible wire, which its providers do not speak', function () {
    $suite = new ContractSuite;

    expect($suite->applies('prism', 'openai-compatible'))->toBeFalse()
        ->and($suite->run('prism', 'openai-compatible'))->toBe([]);
});

it('lists exactly the known gaps, so a gap cannot outlive its cause', function () {
    $gaps = collect(ContractSuite::DRIVERS)
        ->flatMap(fn (string $driver) => (new ContractSuite)->run($driver))
        ->filter(fn (ContractResult $r) => $r->gap)
        ->map(fn (ContractResult $r) => "{$r->driver}|{$r->wire}|{$r->check}")
        ->values()
        ->all();

    expect($gaps)->toBe(['prism|ollama|it adds no limit or option the app did not ask for', 'prism|ollama|a rate limit is RateLimited and says when', 'ai-sdk|ollama|tool history reaches the model', 'ai-sdk|openai-compatible|tool history reaches the model'])
        ->and(array_keys(ContractSuite::GAPS))->toEqualCanonicalizing($gaps);
});

it('fails a gap that has closed, until the line is removed', function () {
    $suite = new ContractSuite;

    $all = $suite->against($suite->make('raw', 'ollama'), 'prism', 'ollama');

    $closed = collect($all)->firstWhere('check', 'a rate limit is RateLimited and says when');

    expect($closed->passed)->toBeFalse()
        ->and($closed->detail)->toContain('the known gap is closed');
});

it('measures the SDK retrying a rate limit inside the driver, which the other drivers do not', function () {
    $attempts = collect(ContractSuite::DRIVERS)->mapWithKeys(function (string $driver) {
        $result = collect((new ContractSuite)->run($driver, 'ollama'))->firstWhere('check', 'a rate limit is RateLimited and says when');

        return [$driver => $result->gap ? 'gap' : $result->detail];
    })->all();

    expect($attempts)->toBe([
        'raw' => 'RateLimited after 1 HTTP attempt',
        'prism' => 'gap',
        'ai-sdk' => 'RateLimited after 2 HTTP attempts',
    ]);
});

it('catches a broken error mapping (Exercise 3)', function () {
    $prism = (new ContractSuite)->make('prism', 'ollama');

    $mismapped = new class($prism) implements ChatModel
    {
        public function __construct(private ChatModel $inner) {}

        public function chat(ChatRequest $request): ChatResponse
        {
            try {
                return $this->inner->chat($request);
            } catch (AiRequestFailed $e) {
                throw new InvalidRequest($e->response);
            }
        }

        public function stream(ChatRequest $request): Generator
        {
            return $this->inner->stream($request);
        }

        public function supports(Capability $capability): bool
        {
            return $this->inner->supports($capability);
        }

        public function name(): string
        {
            return 'mismapped';
        }
    };

    $failed = contractFailures((new ContractSuite)->against($mismapped, 'mismapped', 'ollama'));

    $mapping = array_values(array_filter($failed, fn (string $line) => str_contains($line, 'raised')));

    expect($mapping)->toHaveCount(3)
        ->and(implode("\n", $mapping))->toContain('raised '.InvalidRequest::class.', wanted '.RateLimited::class);
});

describe('ai:contract-test', function () {
    it('runs one driver and prints a line per check', function () {
        $exit = Artisan::call('ai:contract-test', ['--driver' => 'raw']);

        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('raw · ollama', 'raw · openai-compatible', 'pass  text is normalised', '0 failed');
    });

    it('reports the known gap and still passes', function () {
        $exit = Artisan::call('ai:contract-test', ['--driver' => 'prism']);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('gap', 'drops the Retry-After header', 'num_predict 2048', '2 known gaps');
    });

    it('prints JSON, one object per check', function () {
        Artisan::call('ai:contract-test', ['--driver' => 'ai-sdk', '--wire' => 'ollama', '--json' => true]);

        $rows = json_decode(Artisan::output(), true);

        expect($rows)->toHaveCount(14)
            ->and(collect($rows)->every(fn (array $row) => $row['passed']))->toBeTrue()
            ->and($rows[0])->toHaveKeys(['driver', 'wire', 'check', 'passed', 'detail', 'gap']);
    });

    it('refuses a driver or a wire it does not know', function () {
        expect(Artisan::call('ai:contract-test', ['--driver' => 'neuron']))->toBe(1)
            ->and(Artisan::output())->toContain('[neuron] is not a driver');

        expect(Artisan::call('ai:contract-test', ['--driver' => 'raw', '--wire' => 'grpc']))->toBe(1)
            ->and(Artisan::output())->toContain('[grpc] is not a wire format');
    });
});
