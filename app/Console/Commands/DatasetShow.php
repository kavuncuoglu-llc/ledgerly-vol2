<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DatasetShow extends Command
{
    protected $signature = 'dataset:show {dataset : The dataset name under storage/datasets}';

    protected $description = 'Print a dataset version\'s funnel, removals, composition, consent and the hashes training configs pin (Chapter 40)';

    public function handle(): int
    {
        try {
            $dataset = Dataset::named((string) $this->argument('dataset'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $manifest = $dataset->readJson('manifest.json');

        if ($manifest === []) {
            $this->error("Dataset {$dataset->name()} has no manifest.json: run dataset:publish.");

            return self::FAILURE;
        }

        foreach ($this->lines($manifest, $dataset) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function lines(array $m, Dataset $dataset): array
    {
        $f = $m['funnel'];

        $lines = [sprintf('%s · published %s · app commit %s · tokenizer %s', $m['name'], $m['published'], $m['app_commit'], $m['tokenizer'])];

        $lines[] = sprintf('funnel        exported %s → reviewed %s (accepted %s, edited %s; rejected %s)', number_format($f['exported']), number_format($f['reviewed']), number_format($f['accepted']), number_format($f['edited']), number_format($f['rejected']));

        $lines[] = sprintf('              filters −%d · near-duplicates −%d · decontaminated −%d · balance caps −%d  → task examples %s', $f['filters'], $f['near_duplicates'], $f['decontaminated'], $f['balance'], number_format($f['task_examples']));

        $lines[] = 'removals      '.$this->removals($m['removals']);

        $total = max(1, array_sum($m['composition']));

        $lines[] = 'composition   '.implode(' · ', array_map(fn (string $source, int $count) => sprintf('%s %d%%', $source, round($count / $total * 100)), array_keys($m['composition']), $m['composition'])).sprintf('  → %s examples', number_format(array_sum($m['composition'])));

        $lines[] = sprintf('split         train %s · validation %s (%s)', number_format($m['split']['train']), number_format($m['split']['validation']), $m['split']['by']);

        $lines[] = 'consent       '.implode(' · ', array_map(fn (string $source, string $basis) => "{$basis} ({$source})", array_keys($m['consent']), $m['consent']));

        if (isset($m['teacher'])) {
            $lines[] = sprintf('teacher       %s · %s · %s (reviewed %s by %s)', $m['teacher']['role'], $m['teacher']['model'], $m['teacher']['distillation_basis']['license'], $m['teacher']['distillation_basis']['reviewed'], $m['teacher']['distillation_basis']['by']);
        }

        $lines[] = sprintf('review        double-reviewed %d · agreement %s', $m['review']['double_reviewed'], $m['review']['agreement'] === null ? 'n/a' : round($m['review']['agreement'] * 100).'%');

        $lines[] = "stored        {$m['stored']['disk']}:{$m['stored']['path']} (customer-derived files)".($m['hub'] === null ? '' : "\n              hf://{$m['hub']['repository']}@{$m['hub']['revision']} (private, no customer data)");

        $lines[] = 'hashes        '.implode(' · ', array_map(fn (string $file, string $hash) => $file.' '.substr($hash, 0, 14).'…', array_keys($m['files']), $m['files'])).' · manifest.json '.substr($dataset->hash('manifest.json'), 0, 14).'…';

        if (isset($m['derived_from'])) {
            $lines[] = sprintf('derived from  %s without %d examples (%s)', $m['derived_from']['dataset'], $m['derived_from']['forgotten_examples'], $m['derived_from']['reason']);
        }

        if (! empty($m['retirement'])) {
            $lines[] = sprintf('retirement    %s: replaced by %s; delete after %s (%s)', $m['retirement']['status'], $m['retirement']['replaced_by'], $m['retirement']['retire_after'], $m['retirement']['reason']);
        }

        return $lines;
    }

    private function removals(array $removals): string
    {
        $parts = [];

        foreach ($removals as $pass => $value) {
            if (is_array($value) && $value !== []) {
                $parts[] = $pass.' '.implode(', ', array_map(fn ($reason, $count) => "{$reason} {$count}", array_keys($value), $value));
            }

            if (is_int($value) && $value > 0) {
                $parts[] = "{$pass} {$value}";
            }
        }

        return implode(' · ', $parts) ?: 'none';
    }
}
