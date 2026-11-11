<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdrAppend extends Command
{
    protected $signature = 'adr:append
        {record : The decision record, such as docs/adr/fine-tune-local-billing.md}
        {--section= : The section heading to write (Hosted baseline)}
        {--from=eval:last : eval:last (storage/eval/last-compare.json) or the path of an eval:compare result}
        {--note= : A line to put under the heading: what the run was and what it was not}';

    protected $description = 'Write an eval:compare result into a decision record as a section, replacing the section of the same name (Chapter 41)';

    public function handle(): int
    {
        $path = $this->absolute((string) $this->argument('record'));

        if (! is_file($path)) {
            $this->error("No decision record at {$path}.");

            return self::FAILURE;
        }

        $section = trim((string) $this->option('section'));

        if ($section === '') {
            $this->error('Name the section: --section="Hosted baseline".');

            return self::FAILURE;
        }

        $source = $this->option('from') === 'eval:last'
            ? storage_path('eval/last-compare.json')
            : $this->absolute((string) $this->option('from'));

        if (! is_file($source)) {
            $this->error("No comparison at {$source}: run php artisan eval:compare first.");

            return self::FAILURE;
        }

        $result = json_decode((string) file_get_contents($source), true);

        File::put($path, $this->replace((string) file_get_contents($path), $section, $this->render($section, $result)));

        $this->line("  section [{$section}] written to {$this->argument('record')} from {$result['ran_on']} ({$result['set']}, ".count($result['results']).' roles)');

        return self::SUCCESS;
    }

    private function render(string $section, array $result): string
    {
        $roles = $result['results'];

        $lines = ["## {$section}", ''];

        if ($this->option('note')) {
            $lines[] = (string) $this->option('note');

            $lines[] = '';
        }

        $lines[] = "Run {$result['ran_on']} on {$result['set']} ({$result['cases']} cases) with ".implode(', ', $result['checks']).'.';

        $lines[] = '';

        $lines[] = '| | '.implode(' | ', array_map(fn (array $r) => $r['role'], $roles)).' |';

        $lines[] = '| --- | '.implode(' | ', array_fill(0, count($roles), '---')).' |';

        foreach ($this->rows() as $label => [$key, $format]) {
            $lines[] = "| {$label} | ".implode(' | ', array_map(fn (array $r) => $r['skipped'] !== null ? 'not measured' : $format($r['metrics'][$key] ?? null), $roles)).' |';
        }

        foreach ($roles as $r) {
            if ($r['skipped'] !== null) {
                $lines[] = '';

                $lines[] = "{$r['role']} was not measured: {$r['skipped']}.";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, array{0: string, 1: callable}> */
    private function rows(): array
    {
        $percent = fn ($value) => $value === null ? '—' : round($value * 100).'%';

        $drop = fn ($value) => $value === null ? '—' : sprintf('%+.1f points', -$value);

        return [
            'Golden pass rate' => ['golden_pass_rate', $percent],
            'Required facts present' => ['required_facts_rate', $percent],
            'Judge pass rate' => ['judge_pass_rate', $percent],
            'Injection model-level success' => ['injection_model_success_rate', $percent],
            'Tripwire, generation tasks' => ['tripwire_generation_largest_drop', $drop],
            'Tripwire, all tasks' => ['tripwire_all_largest_drop', $drop],
            'Input tokens per answer' => ['input_tokens_per_answer', fn ($value) => $value === null ? '—' : '~'.number_format($value)],
            'Cost per answer' => ['cost_per_answer_usd', fn ($value) => $value === null ? '—' : sprintf('$%.4f', $value)],
        ];
    }

    private function replace(string $record, string $section, string $block): string
    {
        $pattern = '/^## '.preg_quote($section, '/')."\n.*?(?=^## |\z)/ms";

        if (preg_match($pattern, $record) === 1) {
            return (string) preg_replace_callback($pattern, fn () => $block."\n", $record, 1);
        }

        $exit = '/^## Exit/m';

        return preg_match($exit, $record) === 1
            ? (string) preg_replace_callback($exit, fn () => $block."\n## Exit", $record, 1)
            : rtrim($record)."\n\n".$block;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
