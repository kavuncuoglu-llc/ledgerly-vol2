<?php

namespace Tests\Support;

use App\Ai\Training\Dataset;

// Chapter 41: a finished dataset version of a few rows from every source, in a temporary datasets directory.
final class FineTuningWorld
{
    public static function dataset(string $name = 'billing-v1', array $trainSources = ['exemplars', 'exemplars', 'synthetic', 'synthetic', 'replay', 'production', 'production', 'edits'], array $validationSources = ['synthetic', 'production']): Dataset
    {
        TinyDataset::use();

        $dataset = Dataset::named($name);

        foreach (['train' => $trainSources, 'validation' => $validationSources] as $file => $sources) {
            $rows = [];

            $meta = [];

            foreach ($sources as $i => $source) {
                $rows[] = self::row("{$file} question {$i}", "Invoice INV-{$i} has a late fee of 1.5%.");

                $meta[] = ['id' => "{$file}-{$i}", 'source' => $source, 'category' => 'billing', 'subcategory' => 'late-fees', 'conversation' => "c{$file}{$i}", 'tenant' => '', 'user' => '', 'consent' => $source === 'production' ? 'tenant opt-in' : 'n/a'];
            }

            $dataset->write("{$file}.jsonl", $rows);

            $dataset->write("{$file}.meta.jsonl", $meta);
        }

        $dataset->writeJson('manifest.json', ['name' => $name, 'consent' => ['production' => 'tenant opt-in', 'synthetic' => 'n/a'], 'hosts' => []]);

        return $dataset;
    }

    public static function row(string $question, string $reply): array
    {
        return [
            'prompt' => [
                ['role' => 'system', 'content' => 'You are the support assistant.'],
                ['role' => 'user', 'content' => $question],
            ],
            'completion' => [['role' => 'assistant', 'content' => $reply]],
        ];
    }
}
