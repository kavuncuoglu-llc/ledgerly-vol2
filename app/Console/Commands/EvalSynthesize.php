<?php

namespace App\Console\Commands;

use App\Evals\Generators\Generator;
use App\Evals\Generators\HelpCenterCases;
use App\Evals\Generators\LateInvoiceCases;
use App\Evals\Generators\QuestionWriter;
use App\Evals\GoldenSet;
use Illuminate\Console\Command;
use Throwable;

class EvalSynthesize extends Command
{
    protected $signature = 'eval:synthesize
        {--generator=late-invoices : late-invoices (facts from the database) or help-center (one question per chunk)}
        {--count=40 : How many cases to write}
        {--writer=frontier : The provider that writes the wording (a different family from the model under test)}
        {--out= : Where to write the JSON lines (default tests/golden/synthetic/<generator>.jsonl)}';

    protected $description = 'Generate synthetic golden cases, each with a part that is right by construction (Chapter 29, Topic 7)';

    public function handle(GoldenSet $set): int
    {
        $generator = $this->generator((string) $this->option('generator'));

        if ($generator === null) {
            $this->error('Unknown generator. Use late-invoices or help-center.');

            return self::FAILURE;
        }

        $out = $this->option('out') ?: 'tests/golden/synthetic/'.$generator->name().'.jsonl';

        try {
            $cases = iterator_to_array($generator->generate((int) $this->option('count')), false);
        } catch (Throwable $e) {
            $this->error('Generation stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($cases === []) {
            $this->error('The generator produced no cases. Is the database seeded (and, for help-center, ingested)?');

            return self::FAILURE;
        }

        $written = $set->write(str_starts_with($out, '/') ? $out : base_path($out), $cases);

        $this->info("Wrote {$written} synthetic cases to {$out}.");
        $this->line('They are tagged source: synthetic. Review a sample before merging: php artisan eval:review '.$out.' --sample=10');

        return self::SUCCESS;
    }

    private function generator(string $name): ?Generator
    {
        $writer = new QuestionWriter((string) $this->option('writer'));

        return match ($name) {
            'late-invoices' => new LateInvoiceCases($writer),
            'help-center' => new HelpCenterCases($writer),
            default => null,
        };
    }
}
