<?php

namespace App\Ai\Review;

use App\Ai\Deprecations\DeprecationEntry;
use App\Ai\Deprecations\DeprecationTable;
use App\Ai\Doctor\LocalDigestRow;
use App\Ai\Doctor\LocalDigests;
use App\Ai\Packages\InstalledPackages;
use App\Ai\Packages\PackageAudit;
use App\Ai\Packages\PackageAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

// Chapter 50, Topic 9: Topic 7's routine, with the collecting done. One dated document with a section for each of the review's five stages.
// Nothing here decides anything: it lists, links and measures, and the people who read it write the decision records.
final class QuarterlyReview
{
    public function __construct(
        private DeprecationTable $deprecations,
        private PriceAges $prices,
        private PackageAuditor $packages,
        private InstalledPackages $installed,
        private LocalDigests $digests,
        private Trends $trends,
        private DecisionRecords $records,
        private CandidateEvaluator $candidates,
    ) {}

    public static function quarterOf(CarbonImmutable $day): string
    {
        return $day->year.'-Q'.(int) ceil($day->month / 3);
    }

    // The book writes ->quarterly()->weekdays(). Cron reads a day of the month and a day of the week as "either", so that runs on every weekday of January, April, July and October; the first working day needs a check beside the cron.
    public static function isFirstWorkingDayOfQuarter(CarbonImmutable $day): bool
    {
        if (! in_array($day->month, [1, 4, 7, 10], true)) {
            return false;
        }

        $first = $day->startOfMonth();

        while ($first->isWeekend()) {
            $first = $first->addDay();
        }

        return $day->isSameDay($first);
    }

    public static function assertQuarter(string $quarter): void
    {
        if (preg_match('/^\d{4}-Q[1-4]$/', $quarter) !== 1) {
            throw new InvalidArgumentException("A quarter is written 2026-Q4, not [{$quarter}].");
        }
    }

    /** @return array<string, mixed> */
    public function collect(string $quarter, CarbonImmutable $today, bool $offline = false, bool $withCandidates = true): array
    {
        self::assertQuarter($quarter);

        $audits = $this->audits($offline);

        $watched = $withCandidates
            ? $this->watched()
            : [];

        return [
            'quarter' => $quarter,
            'date' => $today->toDateString(),
            'owner' => (string) config('ai-watch.owner'),
            'deprecations' => $this->deprecations->entries($today),
            'models' => $this->modelsAudit(),
            'prices' => $this->prices->all($today),
            'packages' => $audits,
            'locals' => $this->digests->rows(),
            'localReviews' => $this->deprecations->entries($today)->filter(fn (DeprecationEntry $entry) => $entry->state === DeprecationEntry::REVIEW),
            'trends' => $this->trends->lines(),
            'records' => $this->records->all($today),
            'watched' => $watched,
            'withCandidates' => $withCandidates,
        ];
    }

    /** @return list<string> the console summary, in the stages' order */
    public function summary(array $data): array
    {
        $lines = ['1. Collect'];

        $lines[] = '   deprecations   '.$this->deprecationText($data);

        $lines[] = '   prices         '.$this->priceText($data);

        $lines[] = '   packages       '.$this->packageText($data);

        $lines[] = '   local          '.$this->localText($data);

        $lines[] = '   trends         '.implode(' · ', array_values($data['trends']));

        $lines[] = '2. Read           links to each changelog and notice, in the document';

        $lines[] = '3. Test           '.$this->testText($data);

        $due = $data['records']->where('due', true)->count();

        $lines[] = "4. Decide         decision records due: {$due}";

        $lines[] = '5. Update         (filled in as pull requests merge)';

        return $lines;
    }

    public function write(array $data): string
    {
        $directory = base_path(trim((string) config('ai-watch.directory', 'docs/reviews'), '/'));

        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.$data['quarter'].'.md';

        File::put($path, $this->markdown($data));

        return $path;
    }

    public function markdown(array $data): string
    {
        $out = [];

        $out[] = '# AI feature review, '.$data['quarter'];

        $out[] = '';

        $out[] = 'Generated '.$data['date'].' by `php artisan ai:quarterly-review`. Owner: '.$this->md($data['owner']).'. Half a day: read the items, test what is worth testing, write the decision records, then make the changes as pull requests.';

        $out[] = '';

        $out[] = '## 1. Collect';

        $out[] = '';

        array_push($out, ...$this->collectSections($data));

        $out[] = '## 2. Read';

        $out[] = '';

        array_push($out, ...$this->readSection($data));

        $out[] = '## 3. Test';

        $out[] = '';

        array_push($out, ...$this->testSection($data));

        $out[] = '## 4. Decide';

        $out[] = '';

        array_push($out, ...$this->decideSection($data));

        $out[] = '## 5. Update';

        $out[] = '';

        $out[] = '- [ ] New pins, regenerated routes (`php artisan eval:route-table`), refreshed prices, updated appendix rows: one pull request each, linked here as they merge.';

        $out[] = '';

        return implode("\n", $out);
    }

    /** @return list<string> */
    private function collectSections(array $data): array
    {
        $out = ['### Deprecations and dates', ''];

        array_push($out, ...$this->deprecationRows($data));

        array_push($out, '', '### Models (`ai:models:audit`)', '', '```text');

        array_push($out, ...explode("\n", rtrim($this->code($data['models']))));

        array_push($out, '```', '');

        array_push($out, '### Prices', '');

        array_push($out, ...$this->priceRows($data));

        array_push($out, '', '### Packages (`ai:package-audit`)', '');

        array_push($out, ...$this->packageRows($data));

        array_push($out, '', '### Local models (`ai:doctor --local`)', '');

        array_push($out, ...$this->localRows($data));

        array_push($out, '', '### Trends', '');

        foreach ($data['trends'] as $line) {
            $out[] = '- '.$this->md($line);
        }

        $out[] = '';

        return $out;
    }

    /** @return list<string> */
    private function deprecationRows(array $data): array
    {
        $rows = $data['deprecations']->filter(fn (DeprecationEntry $entry) => $entry->state !== DeprecationEntry::REVIEW);

        if ($rows->isEmpty()) {
            return ['Nothing with a date is configured on this machine.'];
        }

        $out = ['| Kind | Id | Date | Days | Replacement | Read |', '| --- | --- | --- | --- | --- | --- |'];

        foreach ($rows as $entry) {
            $out[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $entry->kind,
                $this->md($entry->id).($entry->example ? ' (example entry)' : ''),
                $entry->whenText(),
                $entry->daysLeft ?? '—',
                $this->md($entry->replacement ?: '—'),
                $this->md($entry->read ?: '—'),
            );
        }

        return $out;
    }

    /** @return list<string> */
    private function priceRows(array $data): array
    {
        $out = [];

        foreach ($data['prices'] as $price) {
            $state = match (true) {
                $price['illustrative'] => 'illustrative round numbers, not a quote',
                $price['stale'] => 'STALE: '.($price['days'] === null ? 'no date recorded' : "checked {$price['days']} days ago"),
                default => "checked {$price['days']} days ago",
            };

            $out[] = '- '.$this->md($price['model']).': '.$state;
        }

        return $out === []
            ? ['No priced model is configured; local models cost nothing per token.']
            : $out;
    }

    /** @return list<string> */
    private function packageRows(array $data): array
    {
        if ($data['packages'] === []) {
            return ['No package to audit.'];
        }

        $out = [];

        foreach ($data['packages'] as $entry) {
            if (isset($entry['error'])) {
                $out[] = '- '.$this->md($entry['name']).': not audited ('.$this->md($entry['error']).')';

                continue;
            }

            $audit = $entry['audit'];

            $latest = $audit->signals()['latest'] ?? null;

            $update = $latest !== null && $latest !== $audit->version
                ? ", latest {$latest}"
                : '';

            $open = $audit->openItems() === []
                ? ''
                : ' · open: '.$this->md(implode('; ', $audit->openItems()));

            $out[] = '- '.$this->md($audit->package).' '.$this->md($audit->version).$update.' ('.$entry['decision'].')'.$open;
        }

        return $out;
    }

    /** @return list<string> */
    private function localRows(array $data): array
    {
        $out = [];

        foreach ($data['locals'] as $row) {
            $out[] = '- `'.$this->code($row->line()).'`';
        }

        foreach ($data['localReviews'] as $entry) {
            $out[] = '- '.$this->md($entry->id).': review date '.$entry->date->toDateString().' ('.$entry->daysLeft.' days)';
        }

        return $out === []
            ? ['No local model is configured with an Ollama server.']
            : $out;
    }

    /** @return list<string> */
    private function readSection(array $data): array
    {
        $out = [];

        foreach ($data['deprecations'] as $entry) {
            if ($entry->source !== '' && $this->isUrl($entry->source)) {
                $out[] = '- Notice for '.$this->md($entry->id).': <'.$entry->source.'>';
            }
        }

        foreach ($data['packages'] as $entry) {
            if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $entry['name']) === 1) {
                $out[] = '- '.$entry['name'].': <https://packagist.org/packages/'.$entry['name'].'> (releases and the abandoned flag)';
            }
        }

        $pricing = (string) env('FRONTIER_PRICING_URL', '');

        if ($this->isUrl($pricing)) {
            $out[] = '- Hosted prices: <'.$pricing.'>';
        }

        foreach ($data['records']->where('due', true) as $record) {
            $out[] = '- Decision record due: `'.$record['file'].'`';
        }

        $out[] = '- Read each from the bottom up (Chapter 50, Topic 3): breaking changes, changed defaults, dated deprecations, tokenizer or template changes, prices.';

        $out[] = '';

        return $out;
    }

    /** @return list<string> */
    private function testSection(array $data): array
    {
        if (! $data['withCandidates']) {
            return ['Not run (`--without-candidates`).', ''];
        }

        if ($data['watched'] === []) {
            return ['`config/ai-watch.php` lists no candidate.', ''];
        }

        $out = [];

        foreach ($data['watched'] as $result) {
            $out[] = '- '.$this->md($result->line());
        }

        $out[] = '';

        return $out;
    }

    /** @return list<string> */
    private function decideSection(array $data): array
    {
        $due = $data['records']->where('due', true);

        if ($due->isEmpty()) {
            return ['No decision record is past its review date. Write one for each item above that changes something: adopt, defer with a date, or no change, with the evidence (`php artisan adr:new "..."`).', ''];
        }

        $out = ['| Record | Status | Review date |', '| --- | --- | --- |'];

        foreach ($due as $record) {
            $out[] = '| `'.$record['file'].'` | '.$this->md($record['status']).' | '.$record['review'].' |';
        }

        $out[] = '';

        return $out;
    }

    /** @return list<array{name: string, decision: string, audit?: PackageAudit, error?: string}> */
    private function audits(bool $offline): array
    {
        $listed = [];

        foreach ((array) config('ai-packages.packages') as $row) {
            $listed[(string) $row['name']] = (string) ($row['decision'] ?? '');
        }

        foreach ($this->installed->matching((array) config('ai-packages.ai_patterns')) as $name) {
            $listed[$name] ??= 'in the lock files';
        }

        $out = [];

        foreach ($listed as $name => $decision) {
            if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name) !== 1) {
                $out[] = ['name' => $name, 'decision' => $decision, 'error' => 'not a Composer package name'];

                continue;
            }

            try {
                $out[] = ['name' => $name, 'decision' => $decision, 'audit' => $this->packages->audit($name, $offline)];
            } catch (Throwable $e) {
                $out[] = ['name' => $name, 'decision' => $decision, 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return $out;
    }

    /** @return list<CandidateResult> */
    private function watched(): array
    {
        $sample = (int) config('ai-watch.sample', 12);

        return array_map(fn (array $candidate) => $this->candidates->evaluate($candidate, $sample), array_values((array) config('ai-watch.candidates')));
    }

    private function modelsAudit(): string
    {
        $output = new BufferedOutput;

        try {
            Artisan::call('ai:models:audit', [], $output);
        } catch (Throwable $e) {
            return 'ai:models:audit failed: '.$e->getMessage();
        }

        return $output->fetch();
    }

    private function deprecationText(array $data): string
    {
        $dated = $data['deprecations']->filter(fn (DeprecationEntry $entry) => $entry->isDated());

        $soon = $dated->filter(fn (DeprecationEntry $entry) => $entry->band($this->deprecations->alertDays()) !== null);

        if ($dated->isEmpty()) {
            return 'no dated entry is configured';
        }

        $first = $dated->sortBy('daysLeft')->first();

        return sprintf('%d dated · next: %s %s %s (%d days)%s', $dated->count(), $first->kind, $first->id, $first->date->toDateString(), $first->daysLeft, $soon->isEmpty() ? '' : ' ⚠');
    }

    private function priceText(array $data): string
    {
        $stale = $data['prices']->where('stale', true)->count();

        return ($stale === 0 ? 'no price checked more than 90 days ago' : "{$stale} entries checked > 90 days ago ⚠").' · '.$data['trends']['reconcile'];
    }

    private function packageText(array $data): string
    {
        $updates = [];

        foreach ($data['packages'] as $entry) {
            if (! isset($entry['audit'])) {
                continue;
            }

            $latest = $entry['audit']->signals()['latest'] ?? null;

            if ($latest !== null && $latest !== $entry['audit']->version) {
                $updates[] = $entry['audit']->package.' '.$latest.' available';
            }
        }

        $deferred = collect($data['packages'])->where('decision', 'deferred')->pluck('name')->all();

        return (count($data['packages']) - count($deferred)).' audited'.($updates === [] ? '' : ' · '.implode(' · ', $updates)).($deferred === [] ? '' : ' · deferred: '.implode(', ', $deferred));
    }

    private function localText(array $data): string
    {
        $problems = collect($data['locals'])->filter(fn (LocalDigestRow $row) => $row->isProblem())->count();

        $digests = $data['locals'] === []
            ? 'no local server configured'
            : ($problems === 0 ? 'digests match or are unrecorded' : "{$problems} digest problem(s) ⚠");

        $review = $data['localReviews']->sortBy('daysLeft')->first();

        return $digests.($review === null ? '' : " · {$review->id} review date {$review->date->toDateString()}");
    }

    private function testText(array $data): string
    {
        if (! $data['withCandidates']) {
            return 'skipped (--without-candidates)';
        }

        $count = count($data['watched']);

        $skipped = collect($data['watched'])->filter(fn (CandidateResult $result) => $result->skipped !== null)->count();

        return "watch list: {$count} candidate model(s) · golden sample and tripwire results attached".($skipped === 0 ? '' : " · {$skipped} not evaluated");
    }

    private function isUrl(string $text): bool
    {
        return preg_match('#^https?://[^\s<>()`|]+$#', $text) === 1;
    }

    // Everything that comes from outside the repository (Packagist, a provider's notice, an environment variable) is data: it cannot start a table cell, a link or a code fence.
    private function md(string $text): string
    {
        $flat = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim(str_replace(['|', '`', '<', '>', '[', ']', '*', '_'], ['\\|', "'", '&lt;', '&gt;', '(', ')', '\\*', '\\_'], $flat));
    }

    private function code(string $text): string
    {
        return str_replace('```', "'''", $text);
    }
}
