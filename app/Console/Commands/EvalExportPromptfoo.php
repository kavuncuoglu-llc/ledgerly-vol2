<?php

namespace App\Console\Commands;

use App\Evals\GoldenSet;
use App\Evals\PromptfooExporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class EvalExportPromptfoo extends Command
{
    protected $signature = 'eval:export-promptfoo
        {--subset=all : all, smoke, human, synthetic or production}
        {--with-judge : Add the rubric judge from evals/judges (nightly)}
        {--out=tests/golden/promptfoo.yaml : Where to write the promptfoo test cases}';

    protected $description = 'Write the golden set as promptfoo test cases (Chapter 29)';

    public function handle(GoldenSet $set, PromptfooExporter $exporter): int
    {
        try {
            $cases = $set->subset((string) $this->option('subset'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($cases->isEmpty()) {
            $this->error('The golden set has no cases for that subset. Is tests/golden empty?');

            return self::FAILURE;
        }

        $out = str_starts_with((string) $this->option('out'), '/')
            ? (string) $this->option('out')
            : base_path((string) $this->option('out'));

        $count = $exporter->write($out, $cases, (bool) $this->option('with-judge'));

        $this->info("Wrote {$count} promptfoo test cases to ".$this->option('out').($this->option('with-judge') ? ' (with the rubric judge)' : '').'.');

        return self::SUCCESS;
    }
}
