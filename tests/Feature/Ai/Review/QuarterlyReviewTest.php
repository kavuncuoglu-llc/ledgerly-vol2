<?php

use App\Ai\Review\CandidateEvaluator;
use App\Ai\Review\CandidateResult;
use App\Ai\Review\DecisionRecords;
use App\Ai\Review\PriceAges;
use App\Ai\Review\QuarterlyReview;
use App\Notifications\QuarterlyReviewReady;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

// Chapter 50, Topic 9: the collecting, done by a command, with nothing called that costs money.
function reviewDirectory(): string
{
    return 'storage/framework/testing/reviews';
}

function readReview(string $quarter): string
{
    return File::get(base_path(reviewDirectory().'/'.$quarter.'.md'));
}

beforeEach(function () {
    config(['ai-watch.directory' => reviewDirectory()]);

    Http::swap(new Factory);

    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'llama3.1:8b', 'digest' => '3f1c9a0000000000']]])]);

    config(['ai.providers.local.base_url' => 'http://ollama.test:11434/v1', 'ai.providers.local.model' => 'llama3.1:8b', 'ai.providers.local.digest' => 'sha256:3f1c9a0000000000']);

    app()->bind(CandidateEvaluator::class, fn () => new class implements CandidateEvaluator
    {
        public array $seen = [];

        public function evaluate(array $candidate, int $sample): CandidateResult
        {
            return new CandidateResult($candidate['name'], $candidate['model'], $candidate['replaces'], ['golden_pass_rate' => 0.91, 'required_facts_rate' => 0.95, 'cases' => $sample, 'latency_p50_s' => 4.2], ['golden_pass_rate' => 0.94]);
        }
    });
});

afterEach(function () {
    File::deleteDirectory(base_path(reviewDirectory()));
});

describe('the document', function () {
    it('has a section for each of the five stages and names the quarter', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true])
            ->expectsOutputToContain('wrote storage/framework/testing/reviews/2026-Q4.md')
            ->expectsOutputToContain('1. Collect')
            ->expectsOutputToContain('2. Read')
            ->expectsOutputToContain('3. Test')
            ->expectsOutputToContain('4. Decide')
            ->expectsOutputToContain('5. Update')
            ->assertSuccessful();

        $document = readReview('2026-Q4');

        expect($document)->toContain('# AI feature review, 2026-Q4')
            ->and($document)->toContain('Generated 2026-11-04')
            ->and($document)->toContain('## 1. Collect')
            ->and($document)->toContain('## 2. Read')
            ->and($document)->toContain('## 3. Test')
            ->and($document)->toContain('## 4. Decide')
            ->and($document)->toContain('## 5. Update')
            ->and($document)->toContain('### Deprecations and dates')
            ->and($document)->toContain('### Prices')
            ->and($document)->toContain('### Packages')
            ->and($document)->toContain('### Local models')
            ->and($document)->toContain('### Trends');
    });

    it('lists every entry in config/ai-deprecations.php with days remaining', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('provider-x/v1/completions (example entry) | 2027-01-15 | 72 |')
            ->and(readReview('2026-Q4'))->toContain('llama3.1:8b: review date 2027-01-31 (88 days)');
    });

    it('audits every listed package, the deferred ones included, from the lock files when offline', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('prism-php/prism v0.101.0 (adopted)')
            ->and(readReview('2026-Q4'))->toContain('maestroerror/laragent')
            ->and(readReview('2026-Q4'))->toContain('(deferred)');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'packagist'));
    });

    it('reports the digest of a local model against the one on record', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('✓ matches config');
    });

    it('lists every decision record whose review date has passed', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2027-Q1', '--today' => '2027-03-01', '--offline' => true, '--without-candidates' => true])
            ->expectsOutputToContain('decision records due: '.app(DecisionRecords::class)->due(CarbonImmutable::parse('2027-03-01'))->count())
            ->assertSuccessful();

        expect(app(DecisionRecords::class)->due(CarbonImmutable::parse('2027-03-01'))->count())->toBeGreaterThanOrEqual(3)
            ->and(readReview('2027-Q1'))->toContain('docs/adr/toon-spec-pinned.md')
            ->and(app(DecisionRecords::class)->due(CarbonImmutable::parse('2026-11-04'))->count())->toBe(0);
    });

    it('defaults to the quarter of today', function () {
        $this->artisan('ai:quarterly-review', ['--today' => '2027-02-15', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        expect(File::exists(base_path(reviewDirectory().'/2027-Q1.md')))->toBeTrue();
    });
});

describe('prices', function () {
    it('flags a price checked more than 90 days ago, and one with no date', function () {
        config(['ai-pricing.checked_on' => '2026-11-03', 'ai-pricing.models' => [
            'fresh' => ['source' => 'https://example.com/p', 'checked' => '2026-10-20'],
            'old' => ['source' => 'https://example.com/p', 'checked' => '2026-07-01'],
            'undated' => ['source' => 'x', 'checked' => ''],
            'round' => ['source' => 'illustrative', 'checked' => '2025-01-01', 'illustrative' => true],
        ]]);

        $rows = app(PriceAges::class)->all(CarbonImmutable::parse('2026-11-04'))->keyBy('model');

        expect($rows['fresh']['stale'])->toBeFalse()
            ->and($rows['old']['stale'])->toBeTrue()
            ->and($rows['old']['days'])->toBe(126)
            ->and($rows['undated']['stale'])->toBeTrue()
            ->and($rows['round']['stale'])->toBeFalse();
    });

    it('reads a price with no date of its own from the price map\'s date', function () {
        config(['ai-pricing.checked_on' => '2026-11-03', 'ai-pricing.models' => ['m' => ['source' => 'x']]]);

        expect(app(PriceAges::class)->all(CarbonImmutable::parse('2026-11-04'))->first()['days'])->toBe(1);
    });

    it('puts the stale ones in the document', function () {
        config(['ai-pricing.models' => ['old-model' => ['source' => 'x', 'checked' => '2026-01-01']]]);

        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])
            ->expectsOutputToContain('1 entries checked > 90 days ago')
            ->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('old-model: STALE');
    });
});

describe('the watch list', function () {
    it('puts each candidate\'s numbers beside the role it would replace', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true])
            ->expectsOutputToContain('watch list: 1 candidate model(s)')
            ->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('llama3.2-1b (llama3.2:1b): golden pass 91% (local 94%) on 12 cases · required facts 95% · p50 4.2 s')
            ->and(readReview('2026-Q4'))->toContain('tripwire not run');
    });

    it('calls no model when told not to', function () {
        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('Not run (`--without-candidates`)');
    });

    it('says why a candidate was not evaluated instead of failing the review', function () {
        app()->bind(CandidateEvaluator::class, fn () => new class implements CandidateEvaluator
        {
            public function evaluate(array $candidate, int $sample): CandidateResult
            {
                return new CandidateResult($candidate['name'], $candidate['model'], 'local', skipped: 'model not found');
            }
        });

        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true])
            ->expectsOutputToContain('1 not evaluated')
            ->assertSuccessful();

        expect(readReview('2026-Q4'))->toContain('not evaluated, model not found');
    });
});

describe('data that comes from outside', function () {
    it('refuses a quarter that is not a quarter, so a name cannot walk out of the directory', function (string $quarter) {
        $this->artisan('ai:quarterly-review', ['--quarter' => $quarter, '--offline' => true])->expectsOutputToContain('A quarter is written 2026-Q4')->assertFailed();

        expect(File::exists(base_path('storage/framework/testing/evil.md')))->toBeFalse();
    })->with(['../evil', '2026-Q5', '2026-q4', '2026-Q4/../../x']);

    it('cannot start a table cell, a link or a code fence from a configured value', function () {
        config(['ai-deprecations.entries' => [
            ['kind' => 'model', 'id' => "m|odel`\n# injected", 'retires' => '2027-03-31', 'replacement' => '[click](https://evil.test)', 'read' => '2026-10-14', 'source' => 'javascript:alert(1)'],
        ]]);

        $this->artisan('ai:quarterly-review', ['--quarter' => '2026-Q4', '--today' => '2026-11-04', '--offline' => true, '--without-candidates' => true])->assertSuccessful();

        $document = readReview('2026-Q4');

        expect($document)->not->toContain("\n# injected")
            ->and($document)->not->toContain('[click](')
            ->and($document)->not->toContain('javascript:alert')
            ->and($document)->toContain('m\\|odel');
    });

    it('rejects a date it cannot read', function () {
        $this->artisan('ai:quarterly-review', ['--today' => 'soon', '--offline' => true])->assertFailed();
    });
});

describe('the schedule', function () {
    it('opens the review on the first working day of each quarter and on no other day', function (string $date, bool $due) {
        expect(QuarterlyReview::isFirstWorkingDayOfQuarter(CarbonImmutable::parse($date)))->toBe($due);
    })->with([
        'Thursday 1 October 2026' => ['2026-10-01', true],
        'the next day' => ['2026-10-02', false],
        'Monday 5 October' => ['2026-10-05', false],
        '1 January 2027 is a Friday' => ['2027-01-01', true],
        '1 April 2028 is a Saturday, so Monday the 3rd' => ['2028-04-01', false],
        'Monday 3 April 2028' => ['2028-04-03', true],
        'a month that is not a quarter start' => ['2026-11-02', false],
    ]);

    it('is registered with a cron that, alone, would run every weekday of the quarter\'s first month (BOOK-ISSUES 253)', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ai:quarterly-review'));

        expect($event->expression)->toBe('0 9 * 1,4,7,10 1-5');

        $cron = new CronExpression('0 9 1 1-12/3 1-5');

        expect($cron->isDue(new DateTime('2026-10-14 09:00')))->toBeTrue();
    });

    it('tells the team channel the review is ready, through the app\'s one webhook', function () {
        Http::swap(new Factory);

        Http::fake(['chat.example.test/*' => Http::response('ok')]);

        config(['ai.budgets.notify.channel_webhook' => 'https://chat.example.test/hook', 'ai.alerts.channel' => '#ai-quality']);

        Notification::route('team', '#ai-quality')->notify(new QuarterlyReviewReady('2026-Q4', 'platform team'));

        Http::assertSent(fn ($request) => str_contains($request['text'], '[#ai-quality]') && str_contains($request['text'], 'docs/reviews/2026-Q4.md') && str_contains($request['text'], 'platform team'));
    });
});
