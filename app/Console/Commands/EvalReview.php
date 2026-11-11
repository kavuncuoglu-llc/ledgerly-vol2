<?php

namespace App\Console\Commands;

use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use Illuminate\Console\Command;

class EvalReview extends Command
{
    protected $signature = 'eval:review
        {file : A JSON-lines file of golden cases (for example a synthetic batch)}
        {--sample=10 : How many cases a person looks at}
        {--seed=1 : The sample is the same for the same seed}';

    protected $description = 'Show a sample of a batch for a person to approve before it is merged (Chapter 29, Topic 7)';

    public function handle(GoldenSet $set): int
    {
        $path = str_starts_with((string) $this->argument('file'), '/')
            ? (string) $this->argument('file')
            : base_path((string) $this->argument('file'));

        if (! file_exists($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $cases = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn (string $line) => GoldenCase::fromArray(json_decode($line, true)));

        $seed = (int) $this->option('seed');

        $sample = $cases
            ->sortBy(fn (GoldenCase $case) => crc32("{$seed}:{$case->id}"))
            ->take((int) $this->option('sample'))
            ->values();

        $kept = collect();
        $dropped = collect();

        foreach ($sample as $case) {
            $this->show($case);

            if (! $this->input->isInteractive()) {
                continue;
            }

            if ($this->confirm('Is this a good case: realistic, and are its facts right?', true)) {
                $kept->push($case->id);

                continue;
            }

            $dropped->push($case->id);
        }

        if (! $this->input->isInteractive()) {
            $this->line("Showed {$sample->count()} of {$cases->count()} cases. Run it in a terminal to approve them.");

            return self::SUCCESS;
        }

        if ($dropped->isNotEmpty() && $this->confirm("Remove the {$dropped->count()} rejected case(s) from the file?", true)) {
            $set->write($path, $cases->reject(fn (GoldenCase $case) => $dropped->contains($case->id)));
        }

        $this->info("Reviewed {$sample->count()} of {$cases->count()}: {$kept->count()} approved, {$dropped->count()} rejected.");

        return $dropped->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function show(GoldenCase $case): void
    {
        $this->newLine();
        $this->line("<options=bold>{$case->id}</>  [{$case->source}, {$case->expectedBehavior}]");
        $this->line('Q: '.$case->question);
        $this->line('must include: '.($case->mustInclude === [] ? '-' : implode(', ', $case->mustInclude)));
        $this->line('expected chunks: '.($case->expectedChunks === [] ? '-' : implode(', ', $case->expectedChunks)));
    }
}
