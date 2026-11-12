<?php

namespace App\Ai\Packages;

use App\Ai\Contract\ContractResult;
use App\Ai\Contract\ContractSuite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

// Chapter 48, Topic 7: the checkable half of the rubric (Figure 48-6) as one report: licence, advisories, maintenance, AI dependencies, stored state, outbound defaults and the contract suite. What it cannot check it lists as open items, so a decision record cannot claim them without someone looking.
final class PackageAuditor
{
    public function __construct(
        private InstalledPackages $installed,
        private Packagist $packagist,
        private StateScanner $state,
        private OutboundScanner $outbound,
        private AuditHistory $history,
        private ContractSuite $contract,
    ) {}

    public function audit(string $name, bool $offline = false): PackageAudit
    {
        $facts = $this->installed->find($name);

        $versions = [];
        $note = null;

        if (! $offline) {
            try {
                $versions = $this->packagist->versions($name);
            } catch (Throwable $e) {
                $note = $e->getMessage();
            }
        }

        $facts ??= $this->remoteFacts($name, $versions);

        $audit = new PackageAudit($name, $facts?->version ?? 'unknown', $facts?->installed ?? false);

        if ($facts === null) {
            return $audit->line('license', 'info', 'not checked: the package is not installed and Packagist could not be read'.($note === null ? '' : " ({$note})"))->open('everything: there is nothing to read');
        }

        $this->license($audit, $facts);
        $this->advisories($audit, $facts, $offline);
        $this->maintenance($audit, $facts, $versions, $offline, $note);
        $this->dependencies($audit, $facts, $offline);

        $directory = $facts->directory ?? $this->archive($name, $facts, $versions, $offline);

        $this->state($audit, $facts, $directory);
        $this->outbound($audit, $facts, $directory);
        $this->contractSuite($audit, $name);
        $this->openItems($audit, $name);
        $this->compare($audit);

        return $audit;
    }

    private function remoteFacts(string $name, array $versions): ?PackageFacts
    {
        $stable = $this->packagist->stable($versions);

        if ($stable === []) {
            return null;
        }

        $latest = $stable[0];

        return new PackageFacts(
            name: $name,
            version: (string) $latest['version'],
            installed: false,
            licenses: array_values((array) ($latest['license'] ?? [])),
            require: (array) ($latest['require'] ?? []),
            released: CarbonImmutable::parse($latest['time']),
            directory: null,
            abandoned: $latest['abandoned'] ?? null,
        );
    }

    private function license(PackageAudit $audit, PackageFacts $facts): void
    {
        $allowed = (array) config('ai-packages.licenses_allowed');

        $audit->signal('license', $facts->licenses);

        if ($facts->licenses === []) {
            $audit->line('license', 'fail', 'none declared · not on the allowlist');

            return;
        }

        $outside = array_values(array_diff($facts->licenses, $allowed));

        if ($outside !== []) {
            $audit->line('license', 'fail', implode(', ', $facts->licenses).' · not on the allowlist ('.implode(', ', $outside).')');

            return;
        }

        $audit->line('license', 'ok', implode(', ', $facts->licenses).' · on the allowlist');
    }

    private function advisories(PackageAudit $audit, PackageFacts $facts, bool $offline): void
    {
        if ($offline) {
            $audit->line('advisories', 'info', 'not checked (offline)');

            return;
        }

        try {
            $found = $facts->installed
                ? $this->composerAudit($facts->name)
                : $this->packagistAdvisories($facts);
        } catch (Throwable $e) {
            $audit->line('advisories', 'info', 'not checked: '.$e->getMessage());

            return;
        }

        $audit->signal('advisories', count($found));

        $scope = $facts->installed
            ? 'the installed version'
            : "the latest release ({$facts->version})";

        if ($found === []) {
            $audit->line('advisories', 'ok', "none for {$scope}");

            return;
        }

        $titles = implode('; ', array_map(fn (array $advisory) => ($advisory['cve'] ?? $advisory['advisoryId'] ?? 'advisory').': '.($advisory['title'] ?? ''), $found));

        $audit->line('advisories', 'fail', count($found)." for {$scope}: {$titles}");
    }

    /** @return list<array> */
    private function composerAudit(string $name): array
    {
        $result = Process::path(base_path())->timeout(60)->run(['composer', 'audit', '--locked', '--format=json', '--no-interaction']);

        $document = json_decode($result->output(), true);

        if (! is_array($document)) {
            throw new \RuntimeException('composer audit did not return JSON'.($result->errorOutput() === '' ? '' : ': '.trim($result->errorOutput())));
        }

        return array_values($document['advisories'][$name] ?? []);
    }

    /** @return list<array> */
    private function packagistAdvisories(PackageFacts $facts): array
    {
        return array_values(array_filter($this->packagist->advisories($facts->name), fn (array $advisory) => $this->packagist->affects($advisory, $facts->version)));
    }

    private function maintenance(PackageAudit $audit, PackageFacts $facts, array $versions, bool $offline, ?string $note): void
    {
        $stable = $this->packagist->stable($versions);

        if ($stable === []) {
            $audit->line('maintenance', 'info', $offline
                ? 'not checked (offline): the installed copy is '.$facts->version.($facts->released === null ? '' : ', released '.$facts->released->toDateString())
                : 'not checked: '.($note ?? 'no stable release found'));

            return;
        }

        $last = $this->packagist->releasedAt($stable[0]);

        $recent = count(array_filter($stable, fn (array $version) => $this->packagist->releasedAt($version)->greaterThan(now()->subDays((int) config('ai-packages.health.recent_days')))));

        $days = (int) $last->diffInDays(now());

        $abandoned = $stable[0]['abandoned'] ?? $facts->abandoned;

        $audit->signal('latest', $stable[0]['version']);
        $audit->signal('last_release', $last->toDateString());
        $audit->signal('releases_6m', $recent);
        $audit->signal('abandoned', $abandoned === null ? false : $abandoned);

        $text = 'last release '.$last->diffForHumans(now(), ['syntax' => CarbonImmutable::DIFF_ABSOLUTE]).' ago ('.$stable[0]['version'].')'
            ." · releases in 6 months: {$recent}"
            .' · '.($abandoned ? 'ABANDONED'.(is_string($abandoned) ? " (use {$abandoned})" : '') : 'not abandoned');

        if ($facts->installed && $facts->version !== $stable[0]['version']) {
            $text .= " · installed {$facts->version}";
        }

        $status = match (true) {
            (bool) $abandoned => 'fail',
            $days > (int) config('ai-packages.health.quiet_days') => 'warn',
            default => 'ok',
        };

        $audit->line('maintenance', $status, $text);
    }

    private function dependencies(PackageAudit $audit, PackageFacts $facts, bool $offline): void
    {
        $patterns = (array) config('ai-packages.ai_patterns');

        $found = [];

        $queue = array_keys($facts->require);

        $seen = [$facts->name];

        $fetches = 0;

        while ($queue !== []) {
            $dependency = array_shift($queue);

            if (in_array($dependency, $seen, true) || $dependency === 'php' || str_starts_with($dependency, 'ext-') || str_starts_with($dependency, 'lib-')) {
                continue;
            }

            $seen[] = $dependency;

            $isAi = $this->installed->isAi($dependency, $patterns);

            if ($isAi) {
                $found[] = $dependency;
            }

            $installed = $this->installed->find($dependency);

            if ($installed !== null) {
                array_push($queue, ...array_keys($installed->require));

                continue;
            }

            if ($isAi && ! $offline && $fetches++ < 5) {
                try {
                    $versions = $this->packagist->stable($this->packagist->versions($dependency));

                    array_push($queue, ...array_keys((array) ($versions[0]['require'] ?? [])));
                } catch (Throwable) {
                    continue;
                }
            }
        }

        $audit->signal('ai_dependencies', $found);

        $audit->line('AI dependencies', 'info', $found === []
            ? 'no AI packages in its dependency tree'
            : implode(', ', $found).' (its risks travel down the tree)');
    }

    private function archive(string $name, PackageFacts $facts, array $versions, bool $offline): ?string
    {
        if ($offline) {
            return null;
        }

        foreach ($this->packagist->stable($versions) as $version) {
            if ($version['version'] === $facts->version) {
                try {
                    return $this->packagist->archive($name, $version);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function state(PackageAudit $audit, PackageFacts $facts, ?string $directory): void
    {
        if ($directory === null) {
            $audit->line('stored state', 'info', 'not checked: there is no copy of the package to read');

            $audit->open('stored state: read the package and the data map by hand');

            return;
        }

        $state = $this->state->scan($directory);

        $audit->signal('state', ['tables' => $state['tables'], 'stores' => count($state['stores'])]);

        $declared = array_key_exists($facts->name, (array) config('ai-packages.data_map'));

        if ($state['migrations'] === [] && $state['stores'] === []) {
            $audit->line('stored state', $declared ? 'ok' : 'info', 'no migrations and no configured stores'.($declared ? ' · in the data map' : ''));

            return;
        }

        $parts = [];

        if ($state['migrations'] !== []) {
            $parts[] = count($state['migrations']).' migration'.(count($state['migrations']) === 1 ? '' : 's')
                .($state['tables'] === [] ? '' : ' (tables: '.implode(', ', $state['tables']).')')
                .' · '.($state['runs_migrations'] ? 'run automatically' : ($state['offers_migrations'] ? 'offered for publishing, not run' : 'not loaded by the package'));
        }

        if ($state['stores'] !== []) {
            $parts[] = 'stores configured: '.implode(', ', array_slice($state['stores'], 0, 6)).(count($state['stores']) > 6 ? ' (+'.(count($state['stores']) - 6).' more)' : '');
        }

        $audit->line('stored state', 'warn', implode(' · ', $parts).($declared ? ' · in the data map' : ' → add to the data map'));

        $audit->open('overlap with existing memory and conversation storage');

        $audit->open('whether the data map entry is right');
    }

    private function outbound(PackageAudit $audit, PackageFacts $facts, ?string $directory): void
    {
        $found = $this->outbound->scan($directory, $facts->require, (array) config('ai-packages.outbound_keys'));

        $audit->signal('outbound', ['telemetry' => $found['telemetry'], 'hosts' => count($found['providers'])]);

        $hosts = $found['providers'] === []
            ? ''
            : ' · provider endpoints in its config: '.implode(', ', array_slice($found['providers'], 0, 4)).(count($found['providers']) > 4 ? ' (+'.(count($found['providers']) - 4).' more)' : '');

        if ($directory === null) {
            $audit->line('outbound', 'info', 'not checked: there is no copy of the package to read'.$hosts);

            return;
        }

        if ($found['telemetry'] === []) {
            $audit->line('outbound', 'ok', 'no telemetry or hosted-monitoring defaults found'.$hosts);

            return;
        }

        $audit->line('outbound', 'warn', 'telemetry or monitoring: '.implode('; ', $found['telemetry']).$hosts);

        $audit->open('what the monitoring service does with what it receives (Chapter 34)');
    }

    private function contractSuite(PackageAudit $audit, string $name): void
    {
        $driver = config('ai-packages.drivers')[$name] ?? null;

        if ($driver === null) {
            $audit->line('contract suite', 'info', 'not applicable (not used as a driver)');

            return;
        }

        $results = $this->contract->run($driver);

        $failed = count(array_filter($results, fn (ContractResult $r) => ! $r->passed));

        $gaps = count(array_filter($results, fn (ContractResult $r) => $r->gap));

        $passed = count($results) - $failed - $gaps;

        $audit->signal('contract', ['driver' => $driver, 'passed' => $passed, 'gaps' => $gaps, 'failed' => $failed]);

        $audit->line('contract suite', $failed === 0 ? 'ok' : 'fail', "ai:contract-test --driver={$driver}: {$passed} passed, {$gaps} known gap".($gaps === 1 ? '' : 's').", {$failed} failed");
    }

    private function openItems(PackageAudit $audit, string $name): void
    {
        foreach (['documentation quality', 'team fit', 'golden-set behavior'] as $item) {
            $audit->open($item);
        }

        $spec = config('ai-packages.spec_backed')[$name] ?? null;

        if ($spec !== null) {
            $audit->open("the {$spec} spec version it implements, and whether it is the one you want");
        }

        if ($audit->status('maintenance') === 'warn') {
            $audit->open('whether a quiet package is a stable one');
        }

        if ($audit->status('license') === 'fail') {
            $audit->open('unusual or custom licence terms');
        }
    }

    // The signals of this audit beside the last one's, so the quarterly review reads what moved and not only where things stand.
    private function compare(PackageAudit $audit): void
    {
        $today = now()->toDateString();

        $previous = $this->history->previous($audit->package, $today);

        $entry = ['date' => $today, 'version' => $audit->version] + $audit->signals();

        $this->history->record($audit->package, $entry);

        if ($previous === null) {
            return;
        }

        $changes = [];

        foreach (['version' => 'version', 'releases_6m' => 'releases in 6 months', 'last_release' => 'last release'] as $key => $label) {
            if (($previous[$key] ?? null) !== ($entry[$key] ?? null)) {
                $changes[] = "{$label} {$previous[$key]} → {$entry[$key]}";
            }
        }

        $audit->signal('since', ['date' => $previous['date'], 'changes' => $changes]);
    }
}
