<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DatasetValidate extends Command
{
    protected $signature = 'dataset:validate {path : A JSON Lines file, relative to training/ or absolute}';

    protected $description = 'Run training/scripts/validate_dataset.py on an exported dataset and print its checks (Chapter 27)';

    public function handle(): int
    {
        $path = $this->argument('path');

        $result = Process::path(base_path('training'))
            ->timeout(600)
            ->env(['REQUEST_ID' => (string) Str::uuid()])
            ->run(['uv', 'run', 'python', 'scripts/validate_dataset.py', '--json', $path], function (string $type, string $line) {
                if ($type === 'err') {
                    $this->getOutput()->write("<comment>{$line}</comment>");      // stream Python's progress
                }
            });

        if ($result->failed()) {
            $this->error("validation failed ({$result->exitCode()})");

            return self::FAILURE;
        }

        $report = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);  // stdout is the contract

        $this->table(['check', 'passed', 'detail'], array_map(fn (array $check) => [
            $check['check'],
            $check['passed']
                ? 'yes'
                : 'no',
            $check['detail'],
        ], $report['checks']));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
