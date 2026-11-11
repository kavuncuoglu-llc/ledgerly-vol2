<?php

namespace App\Ai\Training;

use App\Evals\Generators\BillingSftExamples;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

// Chapter 40, Topic 8: a dataset version is its files plus a manifest, and every model is traceable to the exact data it was trained on. Publishing writes the manifest (counts by source and category, every pass's removals with reasons, the consent basis of each source, reviewer agreement, the export's arguments, the app's commit, the tokenizer, a content hash of each file), a dataset card, stores the customer-derived files in the region's own disk and, if asked, the files with no customer data in a private Hub repository. Nothing derived from a conversation is ever handed to the Hub.
final class DatasetPublisher
{
    public const STORED = ['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl', 'manifest.json', 'README.md'];

    public const NO_CUSTOMER_DATA = ['synthetic', 'replay', 'exemplars'];

    public function __construct(private HubUploader $hub) {}

    public function publish(Dataset $dataset, string $region, ?string $hubRepository = null): array
    {
        $this->assertFinished($dataset);

        $export = $dataset->readJson('export.json');

        if (isset($export['region']) && $export['region'] !== $region) {
            throw new InvalidArgumentException("This dataset was exported in region [{$export['region']}]; it is not published to [{$region}].");
        }

        $disk = (string) config('ai.training.region_disk_prefix').$region;

        if (config("filesystems.disks.{$disk}") === null) {
            throw new InvalidArgumentException("No storage disk [{$disk}] for region [{$region}]: add it to config/filesystems.php.");
        }

        $hub = $hubRepository === null
            ? null
            : $this->toHub($dataset, $hubRepository);

        $manifest = $this->manifest($dataset, $region, $disk, $hub, $export);

        $dataset->writeJson('manifest.json', $manifest);

        file_put_contents($dataset->path('README.md'), $this->card($manifest));

        $manifest['files'] = $this->hashes($dataset);

        $dataset->writeJson('manifest.json', $manifest);

        $this->store($dataset, $disk);

        return $manifest;
    }

    private function assertFinished(Dataset $dataset): void
    {
        foreach (['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl', 'review.json', 'clean_report.json', 'split_report.json'] as $file) {
            if (! $dataset->exists($file)) {
                throw new InvalidArgumentException("Dataset {$dataset->name()} is not finished: {$file} is missing (export, review, clean, split first).");
            }
        }
    }

    private function toHub(Dataset $dataset, string $repository): array
    {
        $records = $dataset->records('final');

        $files = [];

        foreach (self::NO_CUSTOMER_DATA as $source) {
            $rows = array_values(array_filter($records, fn (array $record) => $record['meta']['source'] === $source));

            if ($rows === []) {
                continue;
            }

            $dataset->write("hub/{$source}.jsonl", array_map(fn (array $record) => ['prompt' => $record['prompt'], 'completion' => $record['completion']], $rows));

            $files["{$source}.jsonl"] = $dataset->path("hub/{$source}.jsonl");
        }

        return ['repository' => $repository, 'revision' => $this->hub->upload($repository, $files), 'files' => array_keys($files), 'private' => true];
    }

    private function manifest(Dataset $dataset, string $region, string $disk, ?array $hub, array $export): array
    {
        $review = $dataset->readJson('review.json');

        $clean = $dataset->readJson('clean_report.json');

        $split = $dataset->readJson('split_report.json');

        $records = $dataset->records('final');

        $bySource = array_count_values(array_column(array_column($records, 'meta'), 'source'));

        $removals = ['review' => $review['rejection_reasons'] ?? [], 'filters' => $clean['filters'] ?? [], 'near_duplicates' => $clean['near_duplicates'] ?? 0, 'decontaminated' => $clean['decontaminated'] ?? [], 'balance' => $clean['balance'] ?? []];

        return [
            'name' => $dataset->name(),
            'published' => now()->toDateString(),
            'app_commit' => $this->commit(),
            'tokenizer' => $clean['tokenizer'] ?? 'unknown',
            'system_version' => $export['system_version'] ?? null,
            'funnel' => [
                'exported' => $export['examples'] ?? $review['examples'] ?? 0,
                'reviewed' => ($review['accepted'] ?? 0) + ($review['edited'] ?? 0),
                'accepted' => $review['accepted'] ?? 0,
                'edited' => $review['edited'] ?? 0,
                'rejected' => $review['rejected'] ?? 0,
                'filters' => array_sum($clean['filters'] ?? []),
                'near_duplicates' => $clean['near_duplicates'] ?? 0,
                'decontaminated' => array_sum($clean['decontaminated'] ?? []),
                'balance' => array_sum($clean['balance'] ?? []),
                'task_examples' => $clean['output'] ?? 0,
            ],
            'removals' => $removals,
            'counts' => ['by_source' => $bySource, 'by_category' => array_count_values(array_column(array_column($records, 'meta'), 'category')), 'total' => count($records)],
            'composition' => $split['composition'] ?? $bySource,
            'split' => ['train' => $split['train'] ?? 0, 'validation' => $split['validation'] ?? 0, 'by' => $split['split'] ?? 'conversation'],
            'consent' => $this->consent($records),
            'review' => ['double_reviewed' => $review['double_reviewed'] ?? 0, 'agreement' => $review['agreement'] ?? null],
            'provenance' => ['export' => $export, 'clean' => $clean['thresholds'] ?? [], 'generators' => ['synthetic' => 'billing-sft@'.BillingSftExamples::VERSION, 'replay' => 'the base model through Ollama, data/replay.py'], 'seed' => $split['seed'] ?? null],
            'stored' => ['region' => $region, 'disk' => $disk, 'path' => $dataset->name().'/', 'files' => self::STORED],
            'hub' => $hub,
            'files' => [],
            'retirement' => null,
        ];
    }

    private function consent(array $records): array
    {
        $bySource = [];

        foreach ($records as $record) {
            $bySource[$record['meta']['source']][$record['meta']['consent'] ?: 'n/a'] = true;
        }

        return array_map(fn (array $bases) => implode('; ', array_keys($bases)), $bySource);
    }

    private function hashes(Dataset $dataset): array
    {
        $hashes = [];

        foreach (['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl'] as $file) {
            $hashes[$file] = $dataset->hash($file);
        }

        return $hashes;
    }

    private function commit(): string
    {
        $head = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        if (! $head->successful()) {
            return 'unknown';
        }

        $dirty = trim(Process::path(base_path())->run(['git', 'status', '--porcelain', '--', 'app', 'config', 'database', 'routes'])->output()) !== '';

        return trim($head->output()).($dirty ? '+dirty' : '');
    }

    private function store(Dataset $dataset, string $disk): void
    {
        foreach (self::STORED as $file) {
            Storage::disk($disk)->put($dataset->name().'/'.$file, file_get_contents($dataset->path($file)));
        }
    }

    private function card(array $manifest): string
    {
        $lines = ["# {$manifest['name']}", '', 'Purpose: supervised fine-tuning of the local model for billing explanations (docs/adr/fine-tune-local-billing.md). Not for any other use.', '', '## Sources and consent', ''];

        foreach ($manifest['composition'] as $source => $count) {
            $lines[] = "- {$source}: {$count} examples; consent: ".($manifest['consent'][$source] ?? 'n/a');
        }

        $lines[] = '';
        $lines[] = '## Known gaps';
        $lines[] = '';
        $lines[] = '- Token lengths were measured with `'.$manifest['tokenizer'].'`, not necessarily the serving base\'s own tokenizer.';
        $lines[] = '- Names and addresses were replaced only where the export could screen for them: see `provenance.export.patterns_only`.';
        $lines[] = '- Only billing questions, mostly late fees, balances and payment status; no multi-invoice or dispute conversations.';
        $lines[] = '';
        $lines[] = '## Intended model';
        $lines[] = '';
        $lines[] = 'The local 8B model of Chapters 42 and 43, trained with the system message `'.($manifest['system_version'] ?? '?').'`.';
        $lines[] = '';
        $lines[] = 'Examples can be removed by source conversation hash: `php artisan dataset:forget '.$manifest['name'].' <hash>`.';

        return implode("\n", $lines)."\n";
    }
}
