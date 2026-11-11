<?php

namespace App\Ai\Training;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

// Chapter 40, Topic 8 and Chapter 34: weights cannot forget, so an erasure request is honoured before the next training run, by building a new dataset version without the affected source conversations and retiring the old one on the retention schedule. The old version's manifest records the schedule; deleting it, and the model trained on it, is the registry's job (Chapter 44).
final class DatasetEraser
{
    public function __construct(private int $retireAfterDays) {}

    /**
     * @param  list<string>  $conversationHashes  the keyed hashes recorded with every example
     */
    public function forget(Dataset $from, Dataset $to, array $conversationHashes, string $reason): array
    {
        if ($to->exists('train.jsonl')) {
            throw new InvalidArgumentException("Dataset {$to->name()} already exists: a version is never overwritten.");
        }

        $manifest = $from->readJson('manifest.json');

        if ($manifest === []) {
            throw new InvalidArgumentException("Dataset {$from->name()} has no manifest.json: publish it first.");
        }

        $removed = 0;

        $kept = ['train' => [], 'validation' => []];

        foreach (['train', 'validation'] as $split) {
            foreach ($from->joined("{$split}.jsonl", "{$split}.meta.jsonl") as $record) {
                if (in_array($record['meta']['conversation'], $conversationHashes, true)) {
                    $removed++;

                    continue;
                }

                $kept[$split][] = $record;
            }
        }

        if ($removed === 0) {
            throw new InvalidArgumentException('None of those conversations has an example in '.$from->name().': nothing to forget.');
        }

        $this->write($to, $kept);

        $new = $this->newManifest($manifest, $from, $to, $kept, $removed, $reason);

        $to->writeJson('manifest.json', $new);

        File::put($to->path('README.md'), preg_replace('/^# .*/', '# '.$to->name(), File::get($from->path('README.md')), 1));

        $this->store($to, $new['stored']['disk']);

        $this->retire($from, $manifest, $to->name(), $reason);

        return ['removed' => $removed, 'manifest' => $new, 'retire_after' => $from->readJson('manifest.json')['retirement']['retire_after']];
    }

    private function write(Dataset $to, array $kept): void
    {
        foreach ($kept as $split => $records) {
            $to->write("{$split}.jsonl", array_map(fn (array $record) => ['prompt' => $record['prompt'], 'completion' => $record['completion']], $records));

            $to->write("{$split}.meta.jsonl", array_map(fn (array $record) => $record['meta'], $records));
        }
    }

    private function newManifest(array $old, Dataset $from, Dataset $to, array $kept, int $removed, string $reason): array
    {
        foreach (['export.json', 'review.json', 'clean_report.json', 'split_report.json'] as $file) {
            if ($from->exists($file)) {
                File::copy($from->path($file), $to->path($file));
            }
        }

        $all = [...$kept['train'], ...$kept['validation']];

        $bySource = array_count_values(array_column(array_column($all, 'meta'), 'source'));

        $new = $old;

        $new['name'] = $to->name();
        $new['stored']['path'] = $to->name().'/';
        $new['published'] = now()->toDateString();
        $new['composition'] = $bySource;
        $new['counts'] = ['by_source' => $bySource, 'by_category' => array_count_values(array_column(array_column($all, 'meta'), 'category')), 'total' => count($all)];
        $new['split'] = ['train' => count($kept['train']), 'validation' => count($kept['validation']), 'by' => $old['split']['by'] ?? 'conversation'];
        $new['derived_from'] = ['dataset' => $from->name(), 'forgotten_examples' => $removed, 'reason' => $reason, 'at' => now()->toDateString()];
        $new['files'] = array_combine(['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl'], array_map(fn (string $file) => $to->hash($file), ['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl']));
        $new['retirement'] = null;

        return $new;
    }

    private function retire(Dataset $from, array $manifest, string $replacedBy, string $reason): void
    {
        $manifest['retirement'] = ['status' => 'scheduled', 'reason' => $reason, 'replaced_by' => $replacedBy, 'scheduled_on' => now()->toDateString(), 'retire_after' => now()->addDays($this->retireAfterDays)->toDateString()];

        $from->writeJson('manifest.json', $manifest);
    }

    private function store(Dataset $to, string $disk): void
    {
        foreach (DatasetPublisher::STORED as $file) {
            Storage::disk($disk)->put($to->name().'/'.$file, file_get_contents($to->path($file)));
        }
    }
}
