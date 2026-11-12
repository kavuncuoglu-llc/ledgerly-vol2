<?php

use App\Ai\Packages\AuditHistory;
use App\Ai\Packages\InstalledPackages;
use App\Ai\Packages\Packagist;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

// Chapter 48, Topic 7: ai:package-audit on real data (the installed lock file) and on a package Packagist describes but the app does not install, with Packagist, the advisory API and the release archive faked. Nothing here reaches the network.

function agentKitZip(array $extra = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'kit').'.zip';

    $zip = new ZipArchive;

    $zip->open($path, ZipArchive::CREATE);

    $files = [
        'acme-agent-kit-abc123/composer.json' => '{"name":"acme/agent-kit"}',
        'acme-agent-kit-abc123/config/agent-kit.php' => "<?php\n\nreturn [\n    'default_chat_history' => env('AGENT_KIT_HISTORY', 'cache'),\n    'inspector_key' => env('AGENT_KIT_INSPECTOR_KEY'),\n    'providers' => [\n        'hosted' => ['url' => 'https://api.agent-kit-cloud.test/v1'],\n    ],\n];\n",
        'acme-agent-kit-abc123/database/migrations/2026_01_01_000000_create_agent_histories_table.php' => "<?php\n\nSchema::create('agent_histories', function () {});\n",
        'acme-agent-kit-abc123/src/KitServiceProvider.php' => "<?php\n\n\$this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')]);\n",
    ] + $extra;

    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return (string) file_get_contents($path);
}

function fakePackagist(array $overrides = []): void
{
    Http::swap(new Factory);

    Http::preventStrayRequests();

    $now = CarbonImmutable::parse('2026-11-11');

    $versions = [
        ['name' => 'acme/agent-kit', 'version' => '1.4.0', 'version_normalized' => '1.4.0.0', 'time' => $now->subDays(200)->toIso8601String(), 'license' => ['MIT'], 'require' => ['php' => '^8.3', 'openai-php/client' => '^0.10', 'inspector-apm/inspector-php' => '^1.0'], 'dist' => ['url' => 'https://codeload.example.com/acme-agent-kit-1.4.0.zip']],
        ['version' => '1.3.0', 'version_normalized' => '1.3.0.0', 'time' => $now->subDays(230)->toIso8601String()],
        ['version' => 'dev-main', 'version_normalized' => 'dev-main', 'time' => $now->subDays(1)->toIso8601String()],
        ['version' => '2.0.0-beta1', 'version_normalized' => '2.0.0.0-beta1', 'time' => $now->subDays(2)->toIso8601String()],
    ];

    Http::fake([
        'packagist.test/p2/acme/agent-kit.json' => Http::response(['minified' => 'composer/2.0', 'packages' => ['acme/agent-kit' => array_replace_recursive($versions, $overrides['versions'] ?? [])]]),
        'packagist.test/p2/openai-php/client.json' => Http::response(['packages' => ['openai-php/client' => [['name' => 'openai-php/client', 'version' => 'v0.10.1', 'version_normalized' => '0.10.1.0', 'time' => $now->subDays(5)->toIso8601String(), 'require' => ['php' => '^8.1']]]]]),
        'packagist.test/p2/acme/missing.json' => Http::response([], 404),
        'packagist.test/advisories/*' => Http::response(['advisories' => ['acme/agent-kit' => $overrides['advisories'] ?? []]]),
        'codeload.example.com/*' => Http::response(agentKitZip($overrides['zip'] ?? [])),
    ]);
}

beforeEach(function () {
    config([
        'ai-packages.packagist.metadata' => 'https://packagist.test/p2',
        'ai-packages.packagist.advisories' => 'https://packagist.test/advisories/',
    ]);

    File::deleteDirectory(storage_path('app/package-audits'));

    Http::preventStrayRequests();

    CarbonImmutable::setTestNow('2026-11-11 09:00:00');
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/package-audits'));

    CarbonImmutable::setTestNow();
});

function audit(string $package, array $options = []): array
{
    Artisan::call('ai:package-audit', ['package' => $package, '--json' => true] + $options);

    return json_decode(Artisan::output(), true)[0];
}

describe('a package that is not installed', function () {
    it('reads licence, health, dependencies, stored state and outbound defaults from Packagist and the release archive', function () {
        fakePackagist();

        $audit = audit('acme/agent-kit');

        expect($audit['installed'])->toBeFalse()
            ->and($audit['version'])->toBe('1.4.0')
            ->and($audit['lines']['license'])->toBe(['status' => 'ok', 'text' => 'MIT · on the allowlist'])
            ->and($audit['lines']['advisories']['status'])->toBe('ok')
            ->and($audit['lines']['maintenance']['status'])->toBe('warn')
            ->and($audit['lines']['maintenance']['text'])->toContain('last release', 'releases in 6 months: 0', 'not abandoned')
            ->and($audit['lines']['AI dependencies']['text'])->toContain('openai-php/client', 'inspector-apm/inspector-php')
            ->and($audit['lines']['stored state']['status'])->toBe('warn')
            ->and($audit['lines']['stored state']['text'])->toContain('agent_histories', 'offered for publishing, not run', 'default_chat_history', 'add to the data map')
            ->and($audit['lines']['outbound']['status'])->toBe('warn')
            ->and($audit['lines']['outbound']['text'])->toContain('requires inspector-apm/inspector-php', 'inspector_key', 'api.agent-kit-cloud.test')
            ->and($audit['lines']['contract suite']['text'])->toBe('not applicable (not used as a driver)')
            ->and($audit['open_items'])->toContain('documentation quality', 'team fit', 'golden-set behavior', 'overlap with existing memory and conversation storage', 'whether a quiet package is a stable one');
    });

    it('counts only stable releases and expands Packagist\'s minified metadata', function () {
        fakePackagist();

        $versions = app(Packagist::class)->versions('acme/agent-kit');

        $stable = app(Packagist::class)->stable($versions);

        expect(array_column($stable, 'version'))->toBe(['1.4.0', '1.3.0'])
            ->and($stable[1]['license'])->toBe(['MIT'])
            ->and($stable[1]['require'])->toHaveKey('openai-php/client');
    });

    it('fails the gate for an abandoned package, and says what replaces it', function () {
        fakePackagist(['versions' => [0 => ['abandoned' => 'acme/agent-kit-two']]]);

        $audit = audit('acme/agent-kit');

        expect($audit['lines']['maintenance']['status'])->toBe('fail')
            ->and($audit['lines']['maintenance']['text'])->toContain('ABANDONED (use acme/agent-kit-two)');

        expect(Artisan::call('ai:package-audit', ['package' => 'acme/agent-kit', '--strict' => true]))->toBe(1);
    });

    it('fails a licence that is not on the allowlist', function () {
        fakePackagist(['versions' => [0 => ['license' => ['AGPL-3.0-or-later']]]]);

        $audit = audit('acme/agent-kit');

        expect($audit['lines']['license']['status'])->toBe('fail')
            ->and($audit['lines']['license']['text'])->toContain('AGPL-3.0-or-later', 'not on the allowlist')
            ->and($audit['open_items'])->toContain('unusual or custom licence terms');
    });

    it('lists the advisories that affect the latest release and ignores the ones that do not', function () {
        fakePackagist(['advisories' => [
            ['advisoryId' => 'PKSA-1', 'title' => 'Prompt leak in history store', 'cve' => 'CVE-2026-0001', 'affectedVersions' => '>=1.0,<1.5'],
            ['advisoryId' => 'PKSA-2', 'title' => 'Old issue', 'cve' => 'CVE-2025-0002', 'affectedVersions' => '<1.0'],
        ]]);

        $audit = audit('acme/agent-kit');

        expect($audit['lines']['advisories']['status'])->toBe('fail')
            ->and($audit['lines']['advisories']['text'])->toContain('1 for the latest release', 'CVE-2026-0001: Prompt leak in history store')
            ->and($audit['lines']['advisories']['text'])->not->toContain('CVE-2025-0002');
    });

    it('never unpacks an entry outside the target directory, and reads only text files', function () {
        fakePackagist(['zip' => ['acme-agent-kit-abc123/../../evil.php' => '<?php // not written', 'acme-agent-kit-abc123/src/payload.bin' => 'binary']]);

        audit('acme/agent-kit');

        $cache = storage_path('app/package-audits/.cache');

        expect(File::exists(storage_path('app/package-audits/evil.php')))->toBeFalse()
            ->and(File::exists("{$cache}/evil.php"))->toBeFalse()
            ->and(collect(File::allFiles($cache))->map->getFilename()->all())->not->toContain('payload.bin', 'evil.php');
    });

    it('says so when Packagist does not have the package', function () {
        fakePackagist();

        $audit = audit('acme/missing');

        expect($audit['lines']['license']['text'])->toContain('not installed and Packagist could not be read');
    });

    it('refuses a name that is not a package name', function () {
        expect(Artisan::call('ai:package-audit', ['package' => '../../etc/passwd']))->toBe(1);
    });

    it('does not reach the network offline', function () {
        $audit = audit('acme/agent-kit', ['--offline' => true]);

        Http::assertNothingSent();

        expect($audit['lines']['license']['text'])->toContain('could not be read');
    });
});

describe('an installed package', function () {
    it('is audited from the lock file with no network', function () {
        $audit = audit('prism-php/prism', ['--offline' => true]);

        $lock = collect(json_decode(file_get_contents(base_path('composer.lock')), true)['packages'])->firstWhere('name', 'prism-php/prism');

        expect($audit['installed'])->toBeTrue()
            ->and($audit['version'])->toBe($lock['version'])
            ->and($audit['lines']['license']['status'])->toBe('ok')
            ->and($audit['lines']['advisories']['text'])->toBe('not checked (offline)')
            ->and($audit['lines']['maintenance']['text'])->toContain('not checked (offline)', $lock['version'])
            ->and($audit['lines']['AI dependencies']['text'])->toBe('no AI packages in its dependency tree')
            ->and($audit['lines']['stored state']['status'])->toBeIn(['ok', 'info', 'warn'])
            ->and($audit['lines']['contract suite']['status'])->toBe('ok')
            ->and($audit['lines']['contract suite']['text'])->toContain('ai:contract-test --driver=prism', '0 failed');

        Http::assertNothingSent();
    });

    it('runs composer audit for the installed version and reports its advisories', function () {
        fakePackagist();

        Process::fake(['*audit*' => Process::result(output: json_encode(['advisories' => ['prism-php/prism' => [['advisoryId' => 'PKSA-9', 'title' => 'SSRF in image fetch', 'cve' => 'CVE-2026-9999']]], 'abandoned' => []]), exitCode: 1)]);

        Http::fake(['packagist.test/p2/prism-php/prism.json' => Http::response(['packages' => ['prism-php/prism' => [['name' => 'prism-php/prism', 'version' => 'v0.103.0', 'version_normalized' => '0.103.0.0', 'time' => '2026-11-07T00:00:00+00:00', 'license' => ['MIT']]]]])]);

        $audit = audit('prism-php/prism');

        expect($audit['lines']['advisories']['status'])->toBe('fail')
            ->and($audit['lines']['advisories']['text'])->toContain('1 for the installed version', 'CVE-2026-9999: SSRF in image fetch')
            ->and($audit['lines']['maintenance']['text'])->toContain('v0.103.0', 'installed v0.101.0');

        Process::assertRan(fn ($process) => $process->command === ['composer', 'audit', '--locked', '--format=json', '--no-interaction']);
    });

    it('finds the AI-related packages in the lock file, transitive ones included', function () {
        $names = app(InstalledPackages::class)->matching(config('ai-packages.ai_patterns'));

        expect($names)->toContain('prism-php/prism', 'laravel/ai', 'helgesverre/toon', 'laravel/mcp');
    });
});

describe('the quarterly record', function () {
    it('keeps the signals of each audit and says what moved since the last', function () {
        fakePackagist();

        audit('acme/agent-kit');

        CarbonImmutable::setTestNow('2026-12-15 09:00:00');

        fakePackagist(['versions' => [0 => ['time' => '2026-12-10T00:00:00+00:00']]]);

        $second = audit('acme/agent-kit');

        $entries = app(AuditHistory::class)->entries('acme/agent-kit');

        expect($entries)->toHaveCount(2)
            ->and(array_column($entries, 'date'))->toBe(['2026-11-11', '2026-12-15'])
            ->and($second['signals']['since']['date'])->toBe('2026-11-11')
            ->and($second['signals']['since']['changes'])->toContain('last release 2026-04-25 → 2026-12-10');
    });

    it('replaces a second run on the same day', function () {
        fakePackagist();

        audit('acme/agent-kit');
        audit('acme/agent-kit');

        expect(app(AuditHistory::class)->entries('acme/agent-kit'))->toHaveCount(1);
    });
});

describe('the audit list', function () {
    it('names a decision record that exists for every entry, and a review date for every deferral', function () {
        foreach (config('ai-packages.packages') as $entry) {
            expect($entry['decision'])->toBeIn(['adopted', 'baseline', 'deferred', 'rejected']);

            if ($entry['adr'] !== null) {
                expect(is_file(base_path($entry['adr'])))->toBeTrue("{$entry['adr']} does not exist");
            }

            if ($entry['decision'] === 'deferred') {
                expect($entry['review'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
            }
        }
    });

    it('lists every adopted package, and each is installed', function () {
        $installed = collect(app(InstalledPackages::class)->all())->pluck('name');

        $adopted = collect(config('ai-packages.packages'))->where('decision', 'adopted')->pluck('name');

        expect($adopted->diff($installed)->all())->toBe([]);
    });

    it('audits every entry with --all', function () {
        Process::fake(['*audit*' => Process::result(output: json_encode(['advisories' => [], 'abandoned' => []]))]);

        Http::fake(['*' => Http::response([], 404)]);

        Artisan::call('ai:package-audit', ['--all' => true, '--json' => true, '--offline' => true]);

        $rows = json_decode(Artisan::output(), true);

        expect(array_column($rows, 'package'))->toBe(array_column(config('ai-packages.packages'), 'name'));
    });
});
