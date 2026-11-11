<?php

namespace Tests\Support;

use Database\Seeders\TrainingCorpusSeeder;
use Illuminate\Support\Facades\File;

// A small seeded training corpus in a temporary datasets directory, for the Chapter 40 tests.
final class TrainingCorpus
{
    public static function build(int $turnsPerTenant = 4): TrainingCorpusSeeder
    {
        config([
            'ai.training.hash_key' => 'test-hash-key',
            'ai.training.datasets_path' => sys_get_temp_dir().'/datasets-'.bin2hex(random_bytes(4)),
        ]);

        $seeder = new TrainingCorpusSeeder($turnsPerTenant);

        $seeder->run();

        return $seeder;
    }

    public static function cleanUp(): void
    {
        File::deleteDirectory((string) config('ai.training.datasets_path'));
    }
}
