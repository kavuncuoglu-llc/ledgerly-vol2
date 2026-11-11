<?php

use App\Evals\Generators\HelpCenterCases;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Evals\ReviewQueue;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function goldenDirectory(array $cases): string
{
    $dir = sys_get_temp_dir().'/golden-'.uniqid();

    File::ensureDirectoryExists($dir);

    (new GoldenSet($dir))->write("$dir/cases.jsonl", array_map(fn (array $row) => GoldenCase::fromArray($row), $cases));

    app()->bind(GoldenSet::class, fn () => new GoldenSet($dir));

    return $dir;
}

function caseRow(string $id, array $override = []): array
{
    return $override + ['id' => $id, 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => "Question {$id}?", 'expected_behavior' => 'answer', 'must_include' => ['1.5%'], 'must_not_include' => ['INV-5120']];
}

beforeEach(function () {
    config(['ai.eval.fixture_customers' => PHP_INT_MAX]);

    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($customer)->create();

    File::deleteDirectory(storage_path('eval'));
});

afterEach(fn () => File::deleteDirectory(storage_path('eval')));

describe('eval:gates', function () {
    it('runs every case through the pipeline, prints the two verdicts and saves the per-case results', function () {
        goldenDirectory([caseRow('a'), caseRow('b'), caseRow('c')]);

        Ai::fake()->for('support.reply')->respondWith('The fee is 1.5% a month.', times: 3);

        $this->artisan('eval:gates', ['--no-similarity' => true])
            ->expectsOutputToContain('3 cases')
            ->expectsOutputToContain('quality gates   3 / 3 pass all   (100.0%  ≥ 95% required)   ✓')
            ->expectsOutputToContain('never gates     3 / 3 pass       (0 allowed failures)       ✓')
            ->assertExitCode(0);

        $saved = json_decode(File::get(storage_path('eval/last-gates.json')), true);

        expect($saved['kind'])->toBe('gates')->and($saved['cases'])->toBe(3)->and($saved['passed'])->toBeTrue();
    });

    it('fails the run on one leaked fact even when 99% of the quality gates pass', function () {
        goldenDirectory([caseRow('a'), caseRow('b', ['must_not_include' => ['Made-up fee']])]);

        Ai::fake()->for('support.reply')->respondWith('The fee is 1.5% a month. Made-up fee as well.', times: 2);

        $this->artisan('eval:gates', ['--no-similarity' => true])
            ->expectsOutputToContain('never gates     1 / 2 pass       (0 allowed failures)       ✗')
            ->expectsOutputToContain('NEVER: forbidden_facts (leaked Made-up fee)')
            ->assertExitCode(1);
    });

    it('names the failing cases and the reason', function () {
        goldenDirectory(array_map(fn (int $i) => caseRow("c{$i}"), range(1, 20)));

        Ai::fake()->for('support.reply')->respondWith('The fee is 1.5% a month.', times: 19)->respondWith('No idea.');

        $this->artisan('eval:gates', ['--no-similarity' => true])
            ->expectsOutputToContain('quality gates   19 / 20 pass all   (95.0%  ≥ 95% required)   ✓')
            ->expectsOutputToContain('never gates')
            ->expectsOutputToContain('human')
            ->expectsOutputToContain('c9 (required_facts (required fact')
            ->assertExitCode(0);
    });

    it('counts a case whose pipeline blew up as a quality failure instead of stopping', function () {
        goldenDirectory([caseRow('a', ['customer' => 'nobody'])]);

        $this->artisan('eval:gates', ['--no-similarity' => true])->expectsOutputToContain('0 / 1')->assertExitCode(1);
    });

    it('takes a sample size, and refuses an empty set', function () {
        goldenDirectory([caseRow('a'), caseRow('b'), caseRow('c')]);

        Ai::fake()->for('support.reply')->respondWith('The fee is 1.5% a month.', times: 2);

        $this->artisan('eval:gates', ['--sample' => '2', '--no-similarity' => true])->expectsOutputToContain('2 cases')->assertExitCode(0);

        app()->bind(GoldenSet::class, fn () => new GoldenSet('/nonexistent'));

        $this->artisan('eval:gates')->expectsOutputToContain('No golden cases')->assertExitCode(1);
    });
});

describe('eval:synthesize and eval:review', function () {
    it('writes late-invoice cases whose facts come from the database, and only the wording from the model', function () {
        $customer = Customer::factory()->create(['name' => 'Birchwood Print Co.']);

        Invoice::factory()->for($customer)->overdue(40)->withLateFee()->create(['number' => 'INV-7001']);

        Invoice::factory()->for(Customer::first())->create(['number' => 'INV-7002']);

        Ai::fake()->for('eval.synthesize')->respondWith('"Why is there a late fee on INV-7001?"');

        $out = sys_get_temp_dir().'/late-'.uniqid().'.jsonl';

        $this->artisan('eval:synthesize', ['--generator' => 'late-invoices', '--count' => 5, '--writer' => 'local', '--out' => $out])
            ->expectsOutputToContain('Wrote 1 synthetic cases')
            ->assertExitCode(0);

        $case = json_decode(File::get($out), true);

        $fee = number_format(Invoice::where('number', 'INV-7001')->first()->lateFee(), 2);

        expect($case['id'])->toBe('synthetic-late-INV-7001')
            ->and($case['source'])->toBe('synthetic')
            ->and($case['question'])->toBe('Why is there a late fee on INV-7001?')
            ->and($case['must_include'])->toBe(['INV-7001', $fee])
            ->and($case['must_not_include'])->toContain('INV-7002')
            ->and($case['generator'])->toBe('late-invoices@1 via local')
            ->and($case['expected_chunks'])->toBe(['help:billing/late-fees#how-the-fee-is-calculated']);
    });

    it('drops a help-center question that copies the chunk\'s words', function () {
        expect(HelpCenterCases::overlap('How is the late fee calculated on an overdue invoice?', 'The late fee is calculated on the overdue invoice balance'))->toBeGreaterThan(0.6)
            ->and(HelpCenterCases::overlap('What do I pay if my client is slow?', 'A late fee is 1.5% of the outstanding balance'))->toBeLessThan(0.6);
    });

    it('says when a generator is unknown or there is nothing to generate from', function () {
        $this->artisan('eval:synthesize', ['--generator' => 'made-up'])->expectsOutputToContain('Unknown generator')->assertExitCode(1);

        $this->artisan('eval:synthesize', ['--generator' => 'late-invoices', '--writer' => 'local', '--out' => sys_get_temp_dir().'/none.jsonl'])->expectsOutputToContain('produced no cases')->assertExitCode(1);
    });

    it('shows a stable sample for a person to read, and asks a person to approve it', function () {
        $dir = goldenDirectory(array_map(fn (int $i) => caseRow("c{$i}"), range(1, 6)));

        $this->artisan('eval:review', ['file' => "$dir/cases.jsonl", '--sample' => 2, '--no-interaction' => true])
            ->expectsOutputToContain('Showed 2 of 6 cases')
            ->assertExitCode(0);

        $this->artisan('eval:review', ['file' => "$dir/cases.jsonl", '--sample' => 2])
            ->expectsConfirmation('Is this a good case: realistic, and are its facts right?', 'yes')
            ->expectsConfirmation('Is this a good case: realistic, and are its facts right?', 'no')
            ->expectsConfirmation('Remove the 1 rejected case(s) from the file?', 'yes')
            ->expectsOutputToContain('1 approved, 1 rejected')
            ->assertExitCode(1);

        expect(File::lines("$dir/cases.jsonl")->filter()->count())->toBe(5);
    });
});

describe('eval:report', function () {
    beforeEach(function () {
        File::ensureDirectoryExists(storage_path('eval'));

        File::put(storage_path('eval/last-check.json'), json_encode([
            'kind' => 'promptfoo', 'cases' => 25, 'quality_pass_rate' => 0.96, 'quality_threshold' => 0.95, 'never_failures' => 0, 'never_allowed' => 0, 'passed' => true,
            'judge_pass_rate' => 0.88, 'by_source' => ['human' => ['cases' => 20, 'quality_pass_rate' => 0.95], 'synthetic' => ['cases' => 5, 'quality_pass_rate' => 1.0]],
        ]));
    });

    it('logs one run to MLflow: the experiment, the run, the metrics and the params', function () {
        config(['services.mlflow.tracking_uri' => 'http://mlflow.test']);

        Http::fake([
            'mlflow.test/api/2.0/mlflow/experiments/get-by-name*' => Http::response(['error_code' => 'RESOURCE_DOES_NOT_EXIST'], 404),
            'mlflow.test/api/2.0/mlflow/experiments/create' => Http::response(['experiment_id' => '7']),
            'mlflow.test/api/2.0/mlflow/runs/create' => Http::response(['run' => ['info' => ['run_id' => 'abc123']]]),
            'mlflow.test/api/2.0/mlflow/runs/log-batch' => Http::response([]),
            'mlflow.test/api/2.0/mlflow/runs/update' => Http::response([]),
        ]);

        $this->artisan('eval:report', ['--to' => 'mlflow', '--run-name' => 'nightly-test'])
            ->expectsOutputToContain('Ledgerly golden set · 25 cases · quality 96.0% (needs 95%)')
            ->expectsOutputToContain('Logged to MLflow, run abc123, experiment ledgerly-evals.')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'experiments/create') && $request['name'] === 'ledgerly-evals');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), 'runs/log-batch')) {
                return false;
            }

            $metrics = collect($request['metrics'])->pluck('value', 'key');

            return $request['run_id'] === 'abc123'
                && $metrics['quality_pass_rate'] === 0.96
                && $metrics['judge_pass_rate'] === 0.88
                && $metrics['quality_pass_rate_synthetic'] === 1.0
                && collect($request['params'])->pluck('key')->contains('prompt_version');
        });
    });

    it('reuses the experiment when it exists, and fails clearly when MLflow is down', function () {
        config(['services.mlflow.tracking_uri' => 'http://mlflow.test']);

        Http::fake(['mlflow.test/*' => Http::response('down', 503)]);

        $this->artisan('eval:report', ['--to' => 'mlflow'])->expectsOutputToContain('MLflow answered 503')->assertExitCode(1);
    });

    it('posts the summary to the webhook when one is set, and prints it when not', function () {
        $this->artisan('eval:report', ['--notify' => '#ai-quality'])->expectsOutputToContain('EVAL_NOTIFY_WEBHOOK is not set')->assertExitCode(0);

        config(['ai.eval.notify_webhook' => 'https://hooks.example.com/ledgerly']);

        Http::fake(['hooks.example.com/*' => Http::response('ok')]);

        $this->artisan('eval:report', ['--notify' => '#ai-quality'])->expectsOutputToContain('Posted to #ai-quality.')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['channel'] === '#ai-quality' && str_contains($request['text'], 'PASS'));
    });

    it('has nothing to report before a run', function () {
        File::deleteDirectory(storage_path('eval'));

        $this->artisan('eval:report')->expectsOutputToContain('There is no summary to report')->assertExitCode(1);
    });
});

describe('the flywheel: capture, review, promote', function () {
    beforeEach(function () {
        goldenDirectory([caseRow('existing')]);

        $this->conversation = conversationFor(User::first());

        $this->down = Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => 1, 'role' => 'assistant', 'content' => 'Fees are 5%.', 'question' => 'What is the fee? Mail me at maya@acme.example.com', 'tokens' => 5, 'feedback' => 'down']);

        $call = ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'citation_errors' => 2]);

        $this->rejected = Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => 2, 'role' => 'assistant', 'content' => 'x', 'question' => 'Cited something odd?', 'tokens' => 1, 'model_call_id' => $call->id]);
    });

    it('collects candidates from the signals the app already logs', function () {
        $this->artisan('eval:capture', ['--since' => '7d'])
            ->expectsOutputToContain('2 candidates · 1 thumbs down · 1 citation reject · 0 escalated · 0 judge fail')
            ->assertExitCode(0);

        expect(ReviewQueue::default()->open()->pluck('id')->sort()->values()->all())->toBe(["citation_reject:{$this->rejected->id}", "thumbs_down:{$this->down->id}"]);

        $this->artisan('eval:capture')->expectsOutputToContain('0 new in the queue')->assertExitCode(0);
    });

    it('finds a reply that opened a ticket', function () {
        ToolCall::create(['user_id' => User::first()->id, 'conversation_id' => $this->conversation->id, 'tool' => 'open_ticket', 'arguments' => [], 'decision' => 'allowed']);

        $this->artisan('eval:capture', ['--signals' => 'escalated'])->expectsOutputToContain('1 candidates')->assertExitCode(0);
    });

    it('rejects an unknown signal or window', function () {
        $this->artisan('eval:capture', ['--signals' => 'vibes'])->expectsOutputToContain('Unknown signal')->assertExitCode(1);

        $this->artisan('eval:capture', ['--since' => 'lately'])->expectsOutputToContain('--since wants')->assertExitCode(1);
    });

    it('promotes only what a person labeled, redacted, tagged production and linked to the call', function () {
        $this->artisan('eval:capture')->assertExitCode(0);

        $this->artisan('eval:promote')->expectsOutputToContain('Pass --reviewed')->assertExitCode(1);

        $this->artisan('eval:promote', ['--reviewed' => true])->expectsOutputToContain('Nothing labeled')->assertExitCode(0);

        ReviewQueue::default()->update("thumbs_down:{$this->down->id}", ['status' => 'labeled', 'label' => ['expected_behavior' => 'answer', 'category' => 'billing', 'must_include' => ['1.5%'], 'must_not_include' => ['5%']]]);

        $this->artisan('eval:promote', ['--reviewed' => true])->expectsOutputToContain('1 new cases · source: production')->assertExitCode(0);

        $file = collect(File::allFiles(app(GoldenSet::class)->directory().'/production'))->first();

        $case = json_decode(File::get($file->getPathname()), true);

        expect($case['id'])->toBe("production-{$this->down->id}")
            ->and($case['source'])->toBe('production')
            ->and($case['question'])->toBe('What is the fee? Mail me at [email]')
            ->and($case['must_include'])->toBe(['1.5%'])
            ->and(ReviewQueue::default()->open())->toHaveCount(1);

        $this->artisan('eval:promote', ['--reviewed' => true])->expectsOutputToContain('Nothing labeled')->assertExitCode(0);
    });

    it('lists the open candidates when there is no one to ask', function () {
        $this->artisan('eval:capture')->assertExitCode(0);

        $this->artisan('eval:review-queue', ['--list' => true])->expectsOutputToContain('thumbs_down')->assertExitCode(0);
    });
});
