<?php

namespace Tests\Support;

use App\Ai\Training\Dataset;
use Illuminate\Support\Facades\File;

// A handful of hand-made examples in a temporary datasets directory, with the metadata the export writes, so the review, scan, publish and forget tests need no database.
final class TinyDataset
{
    public const KEY = 'test-hash-key';

    public static function use(): string
    {
        $path = sys_get_temp_dir().'/tiny-datasets-'.bin2hex(random_bytes(4));

        config(['ai.training.datasets_path' => $path, 'ai.training.hash_key' => self::KEY]);

        return $path;
    }

    public static function hash(int $conversation): string
    {
        return hash_hmac('sha256', (string) $conversation, self::KEY);
    }

    /** @param list<array> $examples each: conversation (int), turn, question, reply, source, tenant, user, subcategory */
    public static function exported(string $name, array $examples): Dataset
    {
        $dataset = Dataset::named($name);

        $rows = [];

        $meta = [];

        foreach ($examples as $i => $example) {
            $example += ['turn' => 5, 'source' => 'production', 'tenant' => 't'.($i % 9), 'user' => 'u'.$i, 'subcategory' => 'late-fees', 'reply' => 'Invoice INV-'.(1000 + $i).' has $10.00 due.', 'sources' => false];

            $context = $example['sources']
                ? '<untrusted-x source="help_center">The fee is 1.5%.</untrusted-x>'
                : 'context';

            $rows[] = [
                'prompt' => [
                    ['role' => 'system', 'content' => 'system'],
                    ['role' => 'user', 'content' => $context],
                    ['role' => 'user', 'content' => "Question:\n<untrusted-x source=\"customer_message\">\n{$example['question']}\n</untrusted-x>"],
                ],
                'completion' => [['role' => 'assistant', 'content' => $example['reply']]],
            ];

            $meta[] = [
                'id' => 'ex'.$i,
                'source' => $example['source'],
                'category' => 'billing',
                'subcategory' => $example['subcategory'],
                'conversation' => self::hash($example['conversation']),
                'turn' => $example['turn'],
                'tenant' => $example['tenant'],
                'user' => $example['user'],
                'consent' => $example['source'] === 'production' ? 'tenant opt-in under terms v4' : 'n/a',
                'system_version' => '2026-11-11.ft1',
            ];
        }

        $dataset->write('train.jsonl', $rows);

        $dataset->write('manifest.jsonl', $meta);

        return $dataset;
    }

    public static function cleanUp(): void
    {
        File::deleteDirectory((string) config('ai.training.datasets_path'));
    }
}
