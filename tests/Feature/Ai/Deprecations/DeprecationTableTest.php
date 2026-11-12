<?php

use App\Ai\Budgets\Notifier;
use App\Ai\Deprecations\DeprecationAlerts;
use App\Ai\Deprecations\DeprecationEntry;
use App\Ai\Deprecations\DeprecationTable;
use App\Ai\Deprecations\InvalidDeprecationEntry;
use App\Ai\Deprecations\RoutedModels;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Chapter 50, Topic 4: everything with a date, in one table.
function deprecations(array $entries): void
{
    config(['ai-deprecations.entries' => $entries, 'ai-deprecations.alert_days' => [90, 30, 7]]);
}

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date);
}

describe('the table', function () {
    it('counts the days to a retirement', function () {
        deprecations([['kind' => 'model', 'id' => 'frontier-x-2026-01', 'retires' => '2027-03-31', 'replacement' => 'frontier-y', 'read' => '2026-10-14', 'source' => 'https://example.com/notice']]);

        $entry = app(DeprecationTable::class)->entries(day('2026-11-04'))->first();

        expect($entry->daysLeft)->toBe(147)
            ->and($entry->state)->toBe(DeprecationEntry::RETIRES)
            ->and($entry->replacement)->toBe('frontier-y')
            ->and($entry->read)->toBe('2026-10-14');
    });

    it('lets a fine-tune retire with its base and take the base\'s replacement', function () {
        deprecations([
            ['kind' => 'model', 'id' => 'base-1', 'retires' => '2027-03-31', 'replacement' => 'base-2'],
            ['kind' => 'finetune', 'id' => 'ft-on-base-1', 'follows' => 'base-1'],
        ]);

        $entries = app(DeprecationTable::class)->entries(day('2027-03-01'));

        expect($entries[1]->state)->toBe(DeprecationEntry::FOLLOWS)
            ->and($entries[1]->daysLeft)->toBe(30)
            ->and($entries[1]->replacement)->toBe('base-2')
            ->and($entries[1]->follows)->toBe('base-1');
    });

    it('gives a local model a review date and a package an abandoned flag, neither of which is a retirement', function () {
        deprecations([
            ['kind' => 'local', 'id' => 'llama3.1:8b', 'review' => '2027-01-31'],
            ['kind' => 'package', 'id' => 'vendor/old', 'retires' => 'abandoned', 'replacement' => 'vendor/new'],
        ]);

        $entries = app(DeprecationTable::class)->entries(day('2026-11-04'));

        expect($entries[0]->state)->toBe(DeprecationEntry::REVIEW)
            ->and($entries[0]->isDated())->toBeFalse()
            ->and($entries[0]->daysLeft)->toBe(88)
            ->and($entries[1]->state)->toBe(DeprecationEntry::ABANDONED)
            ->and($entries[1]->isDated())->toBeFalse()
            ->and($entries[1]->whenText())->toBe('abandoned');
    });

    it('leaves out an entry whose id is empty and says what is missing', function () {
        deprecations([['kind' => 'model', 'id' => null, 'retires' => '2027-03-31'], ['kind' => 'local', 'id' => 'm', 'review' => '2027-01-31']]);

        expect(app(DeprecationTable::class)->entries(day('2026-11-04')))->toHaveCount(1)
            ->and(app(DeprecationTable::class)->notConfigured())->toBe(['model']);
    });

    it('refuses an entry it cannot read, naming it', function (array $entry, string $message) {
        deprecations([$entry]);

        expect(fn () => app(DeprecationTable::class)->entries(day('2026-11-04')))->toThrow(InvalidDeprecationEntry::class, $message);
    })->with([
        'unknown kind' => [['kind' => 'gadget', 'id' => 'x', 'retires' => '2027-01-01'], 'kind [gadget]'],
        'bad date format' => [['kind' => 'model', 'id' => 'x', 'retires' => '31/03/2027'], 'write YYYY-MM-DD'],
        'impossible date' => [['kind' => 'model', 'id' => 'x', 'retires' => '2027-02-30'], 'not a date'],
        'no date at all' => [['kind' => 'model', 'id' => 'x'], 'write YYYY-MM-DD'],
        'follows nothing' => [['kind' => 'finetune', 'id' => 'x', 'follows' => 'missing'], 'is not an entry'],
    ]);

    it('refuses a chain of follows', function () {
        deprecations([
            ['kind' => 'model', 'id' => 'a', 'retires' => '2027-03-31'],
            ['kind' => 'finetune', 'id' => 'b', 'follows' => 'a'],
            ['kind' => 'finetune', 'id' => 'c', 'follows' => 'b'],
        ]);

        expect(fn () => app(DeprecationTable::class)->entries(day('2026-11-04')))->toThrow(InvalidDeprecationEntry::class, 'follows another entry');
    });

    it('ships a table that reads, with its two example rows marked as examples', function () {
        $entries = app(DeprecationTable::class)->entries(day('2026-11-04'));

        expect($entries->where('example', true)->pluck('kind')->sort()->values()->all())->toBe(['api', 'package'])
            ->and($entries->firstWhere('kind', 'local')->id)->toBe('llama3.1:8b');
    });
});

describe('the bands', function () {
    it('puts an entry in the nearest alert band it has reached', function (string $today, ?int $band) {
        deprecations([['kind' => 'model', 'id' => 'm', 'retires' => '2027-03-31']]);

        expect(app(DeprecationTable::class)->entries(day($today))->first()->band([90, 30, 7]))->toBe($band);
    })->with([
        ['2026-11-04', null],
        ['2026-12-31', 90],
        ['2027-03-01', 30],
        ['2027-03-24', 7],
        ['2027-04-02', 7],
    ]);
});

describe('the alerts', function () {
    beforeEach(function () {
        Cache::flush();

        config(['ai.budgets.notify.channel_webhook' => 'https://chat.example.test/hook', 'ai.budgets.notify.oncall_email' => []]);

        Http::fake(['chat.example.test/*' => Http::response('ok')]);

        $this->alerts = new DeprecationAlerts(app(Notifier::class), app(DeprecationTable::class));

        $this->sent = function () {
            return collect(Http::recorded())->map(fn ($pair) => (str_starts_with($pair[0]['text'], '[PAGE]') ? ['page', $pair[0]['text']] : ['channel', $pair[0]['text']]))->values()->all();
        };

        deprecations([['kind' => 'model', 'id' => 'm-2026', 'retires' => '2027-03-31', 'replacement' => 'm-2027']]);
    });

    $raiseOn = fn (string $today) => fn () => test()->alerts->raise(app(DeprecationTable::class)->entries(day($today)));

    it('says nothing before the first band', function () use ($raiseOn) {
        expect($raiseOn('2026-11-04')())->toBe([]);
    });

    it('raises one alert per band, however often it runs, each with the ladder step for that band', function () use ($raiseOn) {
        $raiseOn('2026-12-31')();
        $raiseOn('2027-01-01')();
        $raiseOn('2027-03-01')();
        $raiseOn('2027-03-02')();
        $raiseOn('2027-03-24')();
        $raiseOn('2027-03-25')();

        $sent = ($this->sent)();

        expect($sent)->toHaveCount(3)
            ->and($sent[0][1])->toContain('Open an evaluation task for the replacement')
            ->and($sent[0][1])->toContain('replacement: m-2027')
            ->and($sent[1][1])->toContain('shadow run or a canary')
            ->and($sent[2][0])->toBe('page')
            ->and($sent[2][1])->toContain('Page the owner');
    });

    it('catches up when the daily run missed a band', function () use ($raiseOn) {
        $raiseOn('2027-03-25')();

        $sent = ($this->sent)();

        expect($sent)->toHaveCount(1)
            ->and($sent[0][0])->toBe('page');
    });
});

describe('the CI check', function () {
    beforeEach(function () {
        config(['ai.providers.retiring' => ['driver' => 'openai-compatible', 'base_url' => 'https://api.example.test/v1', 'model' => 'old-model-1'], 'ai.tiers.mid' => 'retiring']);

        deprecations([['kind' => 'model', 'id' => 'old-model-1', 'retires' => '2027-03-31', 'replacement' => 'new-model-2']]);
    });

    it('finds the model a tier routes to', function () {
        expect(app(RoutedModels::class)->all())->toHaveKey('old-model-1')
            ->and(app(RoutedModels::class)->all()['old-model-1'])->toBe(['retiring']);
    });

    it('passes while the model has not retired', function () {
        $this->artisan('ai:deprecations', ['--strict' => true, '--today' => '2027-03-30'])->assertSuccessful();
    });

    it('fails on the day it retires while anything still routes to it', function () {
        $this->artisan('ai:deprecations', ['--strict' => true, '--today' => '2027-03-31'])
            ->expectsOutputToContain('model old-model-1 retired on 2027-03-31 and the provider [retiring] still routes to it.')
            ->assertFailed();
    });

    it('passes after the day once nothing routes to it', function () {
        config(['ai.tiers.mid' => 'local']);

        $this->artisan('ai:deprecations', ['--strict' => true, '--today' => '2027-04-15'])->assertSuccessful();
    });

    it('prints the table as JSON', function () {
        $this->artisan('ai:deprecations', ['--json' => true, '--today' => '2027-03-01'])->expectsOutputToContain('"days_left": 30')->assertSuccessful();
    });

    it('refuses a date it cannot read and an entry it cannot read', function () {
        $this->artisan('ai:deprecations', ['--today' => 'tomorrow'])->assertFailed();

        deprecations([['kind' => 'nope', 'id' => 'x', 'retires' => '2027-01-01']]);

        $this->artisan('ai:deprecations')->expectsOutputToContain('kind [nope]')->assertFailed();
    });

    it('runs in CI and daily in the scheduler', function () {
        expect(file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('php artisan ai:deprecations --strict');

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command.' @ '.$event->expression);

        expect($scheduled->contains(fn (string $line) => str_contains($line, 'ai:deprecations --notify') && str_ends_with($line, '0 6 * * *')))->toBeTrue();
    });
});
