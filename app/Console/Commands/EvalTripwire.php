<?php

namespace App\Console\Commands;

use App\Evals\Tripwire;
use Illuminate\Console\Command;
use InvalidArgumentException;

class EvalTripwire extends Command
{
    protected $signature = 'eval:tripwire
        {base : The lm_eval output for the base model (a directory or a results file)}
        {candidate : The same for the fine-tune}
        {--max-drop=2.0 : Percentage points a score may fall before the tripwire trips}';

    protected $description = 'Compare benchmark scores before and after a fine-tune (Chapter 30, Topic 6)';

    public function handle(Tripwire $tripwire): int
    {
        $base = $this->path((string) $this->argument('base'));

        $candidate = $this->path((string) $this->argument('candidate'));

        try {
            $rows = $tripwire->compare($tripwire->scores($base), $tripwire->scores($candidate), (float) $this->option('max-drop'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['task', 'base', 'candidate', 'change (points)', ''],
            array_map(fn (array $row) => [$row['task'], number_format($row['base'], 1), number_format($row['candidate'], 1), sprintf('%+.1f', -$row['drop']), $row['tripped'] ? 'TRIPPED' : 'ok'], $rows),
        );

        $tripped = array_filter($rows, fn (array $row) => $row['tripped']);

        if ($tripped === []) {
            $this->info("No score fell by more than {$this->option('max-drop')} points.");

            return self::SUCCESS;
        }

        $this->error(count($tripped).' task(s) fell by more than '.$this->option('max-drop').' points: look at them before you promote this model.');

        return self::FAILURE;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
