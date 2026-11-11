<?php

namespace App\Console\Commands;

use App\Evals\Generators\BillingSftExamples;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DatasetFixture extends Command
{
    protected $signature = 'dataset:fixture
        {--count=200 : Training rows}
        {--held-out=20 : Held-out golden cases}
        {--out=training/data/fixtures/billing_sft_small.jsonl : The training rows}
        {--golden=tests/golden/billing_holdout.jsonl : The held-out golden cases}';

    protected $description = 'Write the synthetic billing fixture for the first LoRA run and its held-out cases from the seeded database (Chapter 39)';

    public function handle(): int
    {
        $examples = new BillingSftExamples((int) $this->option('held-out'));

        $rows = $examples->rows((int) $this->option('count'));

        $cases = $examples->heldOut();

        $this->write((string) $this->option('out'), array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows));

        $this->write((string) $this->option('golden'), array_map(fn (array $case) => json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $cases));

        $this->info(count($rows).' training rows to '.$this->option('out').', '.count($cases).' held-out cases to '.$this->option('golden').'.');

        return self::SUCCESS;
    }

    private function write(string $path, array $lines): void
    {
        $full = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        File::ensureDirectoryExists(dirname($full));

        File::put($full, implode("\n", $lines)."\n");
    }
}
