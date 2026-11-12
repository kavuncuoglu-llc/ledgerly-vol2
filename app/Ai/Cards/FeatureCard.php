<?php

namespace App\Ai\Cards;

use App\Ai\Deprecations\RoutedModels;
use App\Ai\Licenses\LicenseCheck;
use App\Ai\Tools\Risk;
use App\Ai\Tools\ToolRegistry;
use App\Evals\Fairness\FairnessReport;
use App\Evals\Gates;
use App\Evals\Scorecard\Scorecard;
use App\Models\ModelRegistryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

// Chapter 51, Topic 5: the feature card, most of it generated from what the app already knows, so it is true on the day it is read. Purpose, disclosure policy, limitations and owner are hand-written files (reviewed each quarter);
// everything else comes from the routes, the registry, config, the scorecard and the last fairness run. The public summary is the short version for the help center.
final class FeatureCard
{
    public const FEATURES = ['support.reply'];

    public const GENERATED_SECTIONS = ['Models and routes', 'Data', 'Controls'];

    public function __construct(private LicenseCheck $licenses, private RoutedModels $routed, private ToolRegistry $tools, private Scorecard $scorecard) {}

    public static function assertFeature(string $feature): void
    {
        if (! in_array($feature, self::FEATURES, true)) {
            throw new InvalidArgumentException("There is no feature card for [{$feature}]: ".implode(', ', self::FEATURES).'.');
        }
    }

    public function internal(string $feature, CarbonImmutable $today): string
    {
        self::assertFeature($feature);

        $sections = [
            '# Feature card: '.$feature.' · generated '.$today->toDateString().' from app commit '.$this->commit(),
            $this->section('Purpose', $this->hand("docs/cards/{$feature}.purpose.md")),
            $this->section('Models and routes', $this->modelsAndRoutes(), '(from config/ai-routes.php and the registry)'),
            $this->section('Quality', $this->quality($feature), '(from eval:scorecard, last 30 days)'),
            $this->section('Fairness', $this->fairness(), '(from eval:fairness)'),
            $this->section('Data', $this->data(), '(from the data map)'),
            $this->section('Controls', $this->controls()),
            $this->section('Disclosure', $this->hand('docs/policies/disclosure.md')),
            $this->section('Known limitations and owner', $this->hand("docs/cards/{$feature}.limitations.md")),
        ];

        return implode("\n\n", $sections)."\n";
    }

    public function publicSummary(string $feature, CarbonImmutable $today): string
    {
        self::assertFeature($feature);

        $attribution = $this->licenses->attributions();

        $lines = [
            '---',
            'title: About the assistant',
            'category: account',
            'summary: Says what Ledgerly\'s AI assistant is, what it can and cannot do, how it handles your data, and how to reach a person.',
            'updated: '.$today->toDateString(),
            '---',
            '',
            'Ledgerly\'s support assistant is an AI, not a person. It answers questions about using Ledgerly, from the help center and from your own workspace\'s records. A person on the support team is always one click away.',
            '',
            '## What it is for',
            '',
            $this->publicPurpose($feature),
            '',
            '## What it cannot do',
            '',
            '- It does not give legal, tax or medical advice.',
            '- It cannot change your account or move money on its own: anything like that waits for a person to approve it.',
            '- It can be wrong, even when it sounds sure. Answers show their sources; check money figures against your invoice.',
            '',
            '## Your data',
            '',
            'It reads your question and the records of your own workspace that are relevant, and never another customer\'s. Personal details are replaced before any text leaves Ledgerly\'s own servers. Your conversations are not used for training unless your workspace owner turns that on. See [The AI assistant and your data](/help/security/ai-assistant-and-your-data).',
            '',
            '## Reaching a person',
            '',
            'Use "Talk to a person" at the top of the chat. A person on the support team replies by email, usually within one business day.',
        ];

        if ($attribution !== []) {
            array_push($lines, '', '## Models', '', ...array_map(fn (string $line) => '- '.$this->plain($line), $attribution));
        }

        return implode("\n", $lines)."\n";
    }

    // Only the parts that do not change with traffic: they are what a pull request changes and what CI compares (ai:feature-card --check).
    public function stableParts(string $card): array
    {
        $parts = [];

        foreach (preg_split('/^## /m', $card) as $block) {
            $title = trim((string) strtok($block, "\n"));

            foreach ([...self::GENERATED_SECTIONS, 'Purpose', 'Disclosure', 'Known limitations and owner'] as $stable) {
                if (str_starts_with($title, $stable)) {
                    $parts[$stable] = trim($block);
                }
            }
        }

        ksort($parts);

        return $parts;
    }

    private function section(string $title, string $body, string $source = ''): string
    {
        return "## {$title}".($source === '' ? '' : "  {$source}")."\n".trim($body);
    }

    private function hand(string $path): string
    {
        $full = base_path($path);

        if (! File::exists($full)) {
            return "(missing: {$path})";
        }

        $text = trim((string) preg_replace('/\A# .*\n+(Status: .*\n+)?/', '', File::get($full)));

        return $text;
    }

    private function modelsAndRoutes(): string
    {
        $lines = [];

        foreach ((array) config('ai-routes') as $category => $routes) {
            if ($category === '_meta' || $category === 'residency') {
                continue;
            }

            $chain = (array) ($routes['default']['chain'] ?? []);

            $lines[] = $category.' → '.implode(' → ', array_map(fn (string $role) => $this->describeRole($role), $chain));
        }

        if (isset(config('ai-routes')['residency'])) {
            $lines[] = 'residency tenants: in-region roles only, then a person';
        }

        return implode("\n", $lines);
    }

    // A role, and the registry version behind it when it is a registered fine-tune. Model names come from environment variables and differ by machine, so the card does not print them.
    private function describeRole(string $role): string
    {
        $name = (string) (array_search($role, (array) config('ai.registry.roles'), true) ?: '');

        $entry = $name === ''
            ? null
            : $this->productionEntry($name);

        if ($entry !== null) {
            return "{$role} ({$entry->name}@production, v{$entry->version})";
        }

        return $name === ''
            ? $role
            : "{$role} ({$name}@production)";
    }

    private function productionEntry(string $name): ?ModelRegistryEntry
    {
        try {
            return ModelRegistryEntry::query()->where('name', $name)->where('stage', 'production')->orderByDesc('version')->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function quality(string $feature): string
    {
        try {
            $rows = collect($this->scorecard->compute($feature, 30, false)['rows'])->keyBy('metric');
        } catch (Throwable $e) {
            return 'no scorecard on this machine';
        }

        $show = fn (string $metric, string $label, bool $percent = true) => ($v = $rows[$metric]['values']['control'] ?? null) === null
            ? "{$label} no data"
            : $label.' '.($percent ? round($v * 100, 1).'%' : round($v, 2).' s');

        return implode(' · ', [
            $show('golden_quality_pass', 'golden pass'),
            $show('citation_validity_live', 'citations valid'),
            $show('disputes_escalated', 'disputes escalated'),
            $show('false_refusal_rate', 'false refusals'),
            $show('ttft_p95_seconds', 'p95 time to first token', percent: false),
        ]);
    }

    private function fairness(): string
    {
        $latest = FairnessReport::latest();

        if ($latest === null) {
            return 'No fairness run on record: php artisan eval:fairness.';
        }

        $rows = collect($latest['rows']);

        $flagged = $rows->where('state', 'flagged');

        $measured = collect($latest['dimensions'])->filter(fn (string $dimension) => $rows->contains('dimension', $dimension));

        $clear = $measured->reject(fn (string $dimension) => $flagged->contains('dimension', $dimension));

        $unmeasured = collect($latest['dimensions'])->diff($measured);

        $parts = [];

        if ($clear->isNotEmpty()) {
            $parts[] = $clear->implode(', ').': no evidence of disparity';
        }

        foreach ($flagged as $row) {
            $parts[] = sprintf('%s: %s %+.1f points (%s)', $row['dimension'], str_replace('_', ' ', $row['measure']), $row['gap'], $row['pair']);
        }

        if ($unmeasured->isNotEmpty()) {
            $parts[] = $unmeasured->implode(', ').': not measured (no case had a value for its measure)';
        }

        $reviewed = $latest['unreviewed'] === []
            ? ''
            : ' · variation sets not yet reviewed by a person: '.implode(', ', $latest['unreviewed']);

        return sprintf('Run %s on %d base cases, tolerance %.1f points. ', substr((string) $latest['at'], 0, 10), $latest['base_cases'], $latest['tolerance']).implode(' · ', $parts).$reviewed;
    }

    private function data(): string
    {
        $lines = [];

        foreach ($this->routed->routedProviders() as $provider) {
            $privacy = (array) (config('ai.providers')[$provider]['privacy'] ?? []);

            if ($privacy === []) {
                continue;
            }

            $lines[] = sprintf(
                '%s: region %s · retention %s · trains on API data %s · personal data %s',
                $provider,
                blank($privacy['region'] ?? null) ? 'unknown (not set)' : $privacy['region'],
                blank($privacy['retention_days'] ?? null) ? 'unknown (not set)' : $privacy['retention_days'].' days',
                ($privacy['trains_on_api_data'] ?? true) ? 'yes' : 'no',
                ($privacy['redact'] ?? false) ? 'redacted before the call' : 'sent as is (own hardware)',
            );
        }

        return implode("\n", $lines).($lines === [] ? '' : "\n").'Conversations are used for training only when a tenant has turned that setting on (default off), and never for a user who excluded theirs (Chapter 40).';
    }

    private function controls(): string
    {
        $approval = $this->tools->native()->filter(fn ($tool) => $tool->risk() === Risk::HighWrite)->keys()->sort()->implode(', ');

        $lines = [
            'Injection: untrusted text wrapped in random-token tags, a canary, quarantined extraction, output checks (Chapter 32).',
            'Never-gates on every answer: '.implode(', ', Gates::NEVER_GATES).'.',
            "Tool gate: every tool call is validated, scoped to the signed-in customer and audited; approval by a person for {$approval} (Chapter 33).",
            'Goodwill credits are decided by policy (GoodwillCreditPolicy): up to $'.number_format((int) config('ai.goodwill.max_cents') / 100, 2).', '.config('ai.goodwill.max_per_window').' per '.config('ai.goodwill.window_days').' days.',
            'Redaction before any hosted call (Chapter 34); budgets with a kill switch (Chapter 36).',
            'Sensitive-content routing before the assistant answers: safety concerns to a person with priority, out-of-scope requests declined with a way to a person, false refusals escalated and logged.',
        ];

        return implode("\n", array_map(fn (string $line) => '- '.$line, $lines));
    }

    private function publicPurpose(string $feature): string
    {
        $text = $this->hand("docs/cards/{$feature}.purpose.md");

        return trim((string) preg_split('/\n\s*\n/', preg_replace('/^Not for:.*$/m', '', $text) ?? '')[0]);
    }

    private function commit(): string
    {
        try {
            $result = Process::path(base_path())->timeout(5)->run(['git', 'rev-parse', '--short', 'HEAD']);

            return $result->successful()
                ? trim($result->output())
                : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    // Text that came from config or a file becomes one plain line: no new paragraph, link or table can start in it.
    private function plain(string $text): string
    {
        return trim(str_replace(['|', '`', '<', '>', '[', ']'], ['\\|', "'", '&lt;', '&gt;', '(', ')'], (string) preg_replace('/\s+/', ' ', $text)));
    }
}
