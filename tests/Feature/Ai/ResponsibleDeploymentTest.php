<?php

use App\Ai\Cards\FeatureCard;
use App\Ai\Licenses\LicenseCheck;
use App\Ai\Review\DraftHighlighter;
use App\Ai\Review\Reliance;
use App\Evals\Fairness\FairnessReport;
use App\Models\DraftReview;
use App\Models\ModelRegistryEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\DraftReviewSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Chapter 51, Topics 5, 6 and 8: the feature card, the license check, and the reliance metrics.
function licenseConfig(array $licenseOverrides = []): void
{
    config(['ai-licenses.licenses' => ['Test License' => array_merge([
        'group' => 'community',
        'text_version' => 'Test License v1',
        'reviewed' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'by' => 'legal',
        'use' => 'hosted-service',
        'obligations' => ['acceptable_use' => 'docs/policies/acceptable-use-mapping.md'],
    ], $licenseOverrides)], 'ai-licenses.bases' => ['base/Model-1' => 'Test License'], 'ai.tiers' => ['small' => 'local'], 'ai.features' => [], 'ai.providers' => ['local' => ['model' => 'm1', 'license' => 'Test License']]]);

    config(['ai-routes' => ['_meta' => []]]);
}

describe('ai:license-check', function () {
    it('passes for the repository as shipped', function () {
        $this->artisan('ai:license-check')->assertSuccessful();
    });

    it('passes a served model with a reviewed record', function () {
        licenseConfig();

        expect(app(LicenseCheck::class)->failures())->toBe([]);
    });

    it('fails a model served without a license on record', function () {
        licenseConfig();

        config(['ai.providers.local.license' => null]);

        expect(app(LicenseCheck::class)->failures()[0])->toContain('served without a recorded license');

        $this->artisan('ai:license-check')->assertFailed();
    });

    it('fails a license nobody reviewed, in each way a record can be incomplete', function (array $override, string $why) {
        licenseConfig($override);

        expect(implode(' ', app(LicenseCheck::class)->failures()))->toContain($why);
    })->with([
        'no reviewer' => [['by' => ''], '`by`'],
        'no date' => [['reviewed' => ''], '`reviewed`'],
        'no text version' => [['text_version' => null], '`text_version`'],
        'not a date' => [['reviewed' => '2026-02-30'], 'not a date'],
        'a date in the future' => [['reviewed' => '2999-01-01'], 'in the future'],
        'reviewed long ago' => [['reviewed' => '2020-01-01'], 'review it again'],
        'a use that is not one' => [['use' => 'everywhere'], 'use [everywhere]'],
        'an obligation with no place' => [['obligations' => ['acceptable_use' => '']], 'no usable place'],
        'an obligation outside the project' => [['obligations' => ['acceptable_use' => '../../etc/passwd']], 'no usable place'],
        'an obligation whose file is missing' => [['obligations' => ['acceptable_use' => 'docs/policies/nope.md']], 'does not exist'],
    ]);

    it('needs the attribution notice in the place that meets it', function () {
        licenseConfig(['attribution_text' => 'Built with TestModel', 'obligations' => ['attribution' => 'docs/policies/model-naming.md']]);

        expect(implode(' ', app(LicenseCheck::class)->failures()))->toContain('[Built with TestModel] is not in');
    });

    it('exempts a provider served under its own terms', function () {
        licenseConfig();

        config(['ai.providers.local.license' => 'Provider terms']);

        expect(app(LicenseCheck::class)->failures())->toBe([]);
    });

    it('holds a fine-tune to its base\'s license, and fails one whose base has none', function () {
        licenseConfig();

        $entry = ModelRegistryEntry::create(['name' => 'billing', 'version' => 1, 'model' => 'ledgerly-billing-ft', 'base' => 'base/Model-1', 'dataset' => 'billing-v1', 'prompt_version' => 'ft-v1', 'stage' => 'production']);

        expect(app(LicenseCheck::class)->failures())->toBe([]);

        $entry->update(['base' => 'unknown/Model-9']);

        expect(app(LicenseCheck::class)->failures()[0])->toContain('its base [unknown/Model-9] has no license');

        $entry->update(['base' => 'base/Model-1', 'stage' => 'retired']);

        expect(app(LicenseCheck::class)->failures())->toBe([]);
    });

    it('fails a fine-tune whose base license has lost its review', function () {
        licenseConfig(['by' => '']);

        ModelRegistryEntry::create(['name' => 'billing', 'version' => 1, 'model' => 'm', 'base' => 'base/Model-1', 'dataset' => 'd', 'prompt_version' => 'v', 'stage' => 'canary']);

        expect(implode(' | ', app(LicenseCheck::class)->failures()))->toContain('inherits Test License, which is not clean');
    });

    it('runs in CI', function () {
        expect(file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('php artisan ai:license-check');
    });
});

describe('ai:feature-card', function () {
    beforeEach(function () {
        $this->out = 'storage/framework/testing/cards';

        $this->public = 'storage/framework/testing/cards/public.md';

        Process::fake(['*' => Process::result('4be19a2'."\n")]);

        File::deleteDirectory(base_path($this->out));

        File::delete(FairnessReport::path());
    });

    afterEach(function () {
        File::deleteDirectory(base_path($this->out));

        File::delete(FairnessReport::path());
    });

    it('writes an internal card with every section and a public summary', function () {
        $this->artisan('ai:feature-card', ['feature' => 'support.reply', '--out' => $this->out, '--public' => $this->public])
            ->expectsOutputToContain('wrote storage/framework/testing/cards/support.reply.md')
            ->assertSuccessful();

        $card = File::get(base_path($this->out.'/support.reply.md'));

        foreach (['## Purpose', '## Models and routes', '## Quality', '## Fairness', '## Data', '## Controls', '## Disclosure', '## Known limitations and owner'] as $section) {
            expect($card)->toContain($section);
        }

        expect($card)->toContain('from app commit 4be19a2')
            ->and($card)->toContain('Not for: legal or tax advice')
            ->and($card)->toContain('claims_to_be_human')
            ->and($card)->toContain('billing → ')
            ->and($card)->toContain('No fairness run on record');
    });

    it('writes a help-center article that passes the help check, and carries the license attributions', function () {
        $this->artisan('ai:feature-card', ['feature' => 'support.reply', '--out' => $this->out, '--public' => $this->public])->assertSuccessful();

        $public = File::get(base_path($this->public));

        expect($public)->toStartWith("---\ntitle: About the assistant\ncategory: account")
            ->and($public)->toContain('is an AI, not a person')
            ->and($public)->toContain('Built with Llama')
            ->and($public)->toContain('Talk to a person')
            ->and(File::get(base_path('resources/help/account/about-the-assistant.md')))->toContain('Built with Llama');

        expect(Artisan::call('help:check'))->toBe(0);
    });

    it('takes the fairness section from the last run', function () {
        FairnessReport::save(['at' => '2026-11-01T01:30:00+00:00', 'base_cases' => 150, 'tolerance' => 2.0, 'dimensions' => ['name', 'english'], 'unreviewed' => [], 'flagged' => ['english'], 'rows' => [['dimension' => 'name', 'measure' => 'credit_offered', 'gap' => 0.4, 'pair' => 'control vs names-a', 'state' => 'clear'], ['dimension' => 'english', 'measure' => 'required_facts', 'gap' => -3.1, 'pair' => 'control vs non_native', 'state' => 'flagged']]]);

        $this->artisan('ai:feature-card', ['feature' => 'support.reply', '--out' => $this->out])->assertSuccessful();

        expect(File::get(base_path($this->out.'/support.reply.md')))->toContain('name: no evidence of disparity')
            ->and(File::get(base_path($this->out.'/support.reply.md')))->toContain('english: required facts -3.1 points');
    });

    it('says a dimension was not measured when no case had a value for it, instead of calling it clear', function () {
        FairnessReport::save(['at' => '2026-11-01T01:30:00+00:00', 'base_cases' => 5, 'tolerance' => 2.0, 'dimensions' => ['tier', 'english'], 'unreviewed' => [], 'flagged' => [], 'rows' => [['dimension' => 'tier', 'measure' => 'tone', 'gap' => 1.0, 'pair' => 'a vs b', 'state' => 'clear']]]);

        $this->artisan('ai:feature-card', ['feature' => 'support.reply', '--out' => $this->out])->assertSuccessful();

        expect(File::get(base_path($this->out.'/support.reply.md')))->toContain('tier: no evidence of disparity')
            ->and(File::get(base_path($this->out.'/support.reply.md')))->toContain('english: not measured');
    });

    it('is current right after it was written, and stale when something it reads changes', function () {
        $arguments = ['feature' => 'support.reply', '--out' => $this->out, '--public' => $this->public];

        $this->artisan('ai:feature-card', $arguments)->assertSuccessful();

        $this->artisan('ai:feature-card', $arguments + ['--check' => true])->expectsOutputToContain('current')->assertSuccessful();

        config(['ai.goodwill.max_cents' => 9900]);

        $this->artisan('ai:feature-card', $arguments + ['--check' => true])->expectsOutputToContain('Controls')->assertFailed();
    });

    it('does not mind the numbers that move with traffic when it checks', function () {
        $arguments = ['feature' => 'support.reply', '--out' => $this->out];

        $this->artisan('ai:feature-card', $arguments)->assertSuccessful();

        FairnessReport::save(['at' => '2026-11-01T00:00:00+00:00', 'base_cases' => 5, 'tolerance' => 2.0, 'dimensions' => ['name'], 'unreviewed' => [], 'flagged' => [], 'rows' => []]);

        $this->artisan('ai:feature-card', $arguments + ['--check' => true])->assertSuccessful();
    });

    it('is committed, and current', function () {
        $this->artisan('ai:feature-card', ['feature' => 'support.reply', '--public' => 'resources/help/account/about-the-assistant.md', '--check' => true])->assertSuccessful();

        expect(file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('ai:feature-card support.reply');
    });

    it('refuses a feature without a card and any path that leaves the project', function (array $arguments) {
        $this->artisan('ai:feature-card', $arguments)->assertFailed();

        expect(File::exists(base_path('../evil.md')))->toBeFalse();
    })->with([
        'unknown feature' => [['feature' => 'ticket.classify']],
        'a traversal in the output directory' => [['feature' => 'support.reply', '--out' => '../elsewhere']],
        'an absolute public path' => [['feature' => 'support.reply', '--public' => '/tmp/evil.md']],
        'a public path that is not Markdown' => [['feature' => 'support.reply', '--public' => 'public/index.php']],
        'a path with odd characters' => [['feature' => 'support.reply', '--public' => 'docs/a b;rm.md']],
    ]);

    it('keeps text from config out of the public summary\'s structure', function () {
        config(['ai-licenses.licenses' => ['Llama 3.1 Community License' => array_merge(config('ai-licenses.licenses')['Llama 3.1 Community License'], ['attribution_text' => "Built with X\n\n## Injected |"])]]);

        $public = app(FeatureCard::class)->publicSummary('support.reply', CarbonImmutable::parse('2026-11-11'));

        expect($public)->not->toContain("\n## Injected")
            ->and($public)->toContain('Built with X');
    });
});

describe('reliance', function () {
    it('measures how much review keeps pace with trust', function () {
        $reliance = app(Reliance::class);

        $monday = CarbonImmutable::parse('2026-04-13 10:00:00');

        CarbonImmutable::setTestNow($monday);

        foreach ([['same', 'same', 20], ['same', 'same', 10], ['draft one', 'draft two edited', 90], ['same', 'same', 12]] as $i => [$draft, $sent, $seconds]) {
            $review = $reliance->record('triage_reply', $i + 1, $draft, $sent, $seconds);

            $reliance->audit($review, $i === 1);
        }

        CarbonImmutable::setTestNow();

        $week = $reliance->weekly()->first();

        expect($week->week)->toBe('2026-04-13')
            ->and($week->reviews)->toBe(4)
            ->and($week->unedited_share)->toBe(0.75)
            ->and($week->median_review_s)->toBe(16.0)
            ->and($week->audited_unedited_error_rate)->toEqualWithDelta(1 / 3, 0.0001);
    });

    it('computes how far an edit moved the draft', function () {
        $reliance = app(Reliance::class);

        expect($reliance->editDistance('abc', 'abc'))->toBe(0)
            ->and($reliance->editDistance('kitten', 'sitting'))->toBe(3)
            ->and($reliance->editDistance('', 'abc'))->toBe(3)
            ->and($reliance->editDistance('héllo', 'hello'))->toBe(1);
    });

    it('records a review once however often it is saved', function () {
        $reliance = app(Reliance::class);

        $reliance->record('triage_reply', 7, 'a', 'a', 5);

        $reliance->record('triage_reply', 7, 'a', 'ab', 9);

        expect(DraftReview::count())->toBe(1)
            ->and(DraftReview::first()->edit_distance)->toBe(1);
    });

    it('prints the weekly table and the warning, from a month of fictional reviews', function () {
        $this->seed(DraftReviewSeeder::class);

        $this->artisan('ai:reliance')
            ->expectsOutputToContain('2026-04-13')
            ->expectsOutputToContain('Unedited sends climbed while the audit still finds errors')
            ->assertSuccessful();

        expect(DraftReview::count())->toBe(120);
    });

    it('says so when there is nothing to report', function () {
        $this->artisan('ai:reliance')->expectsOutputToContain('No draft reviews on record')->assertSuccessful();
    });
});

describe('the review screen', function () {
    it('highlights every figure and every sentence that states something without a citation', function () {
        $sentences = (new DraftHighlighter)->sentences('Hello Maya. The late fee on INV-4471 is $21.60 [help:billing/late-fees]. It will be waived next month. Can I help with anything else?');

        expect($sentences[0]['uncited'])->toBeFalse()
            ->and($sentences[1]['uncited'])->toBeFalse()
            ->and($sentences[1]['figures'])->toBe(['INV-4471', '$21.60'])
            ->and($sentences[2]['uncited'])->toBeTrue()
            ->and($sentences[3]['uncited'])->toBeFalse();
    });

    it('draws attention with escaped markup, so a draft cannot inject any', function () {
        $html = (new DraftHighlighter)->html('The fee is $20. <script>alert(1)</script> You will get a refund soon.');

        expect($html)->toContain('<mark class="figure">$20</mark>')
            ->and($html)->toContain('<span class="uncited"')
            ->and($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;script&gt;');
    });

    it('does not ask for attention for a draft that cites and has no figures', function () {
        expect((new DraftHighlighter)->needsAttention('Open Settings and choose Change plan [help:billing/change-your-plan].'))->toBeFalse()
            ->and((new DraftHighlighter)->needsAttention('Your plan costs 29 dollars a month.'))->toBeTrue();
    });
});
