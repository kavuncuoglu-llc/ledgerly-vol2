<?php

namespace App\Console\Commands;

use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Training\Dataset;
use App\Ai\Training\DoubtJudge;
use App\Ai\Training\ReviewApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class DatasetReview extends Command
{
    protected $signature = 'dataset:review
        {dataset : The dataset name under storage/datasets, e.g. billing-v1}
        {--triage=none : none, or judge: sort the queue by the judge\'s doubt (the judge never labels)}
        {--judge-provider= : The provider that scores doubt (default: the local role)}
        {--apply= : A review file: the reviewer\'s accept, edit and reject decisions and a second reviewer\'s on a tenth}
        {--limit=15 : How many queue entries to print}';

    protected $description = 'Open the review queue of an exported dataset, or apply a reviewer\'s decisions to it (Chapter 40)';

    public function handle(PersonalDataDetector $pii, DoubtJudge $judge): int
    {
        try {
            $dataset = Dataset::named((string) $this->argument('dataset'));

            $joined = $dataset->joined();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            return $this->apply($dataset, $joined, $pii);
        }

        return $this->queue($dataset, $joined, $judge);
    }

    private function queue(Dataset $dataset, array $joined, DoubtJudge $judge): int
    {
        $triage = (string) $this->option('triage');

        if (! in_array($triage, ['none', 'judge'], true)) {
            $this->error('--triage is none or judge.');

            return self::FAILURE;
        }

        $queue = $triage === 'judge'
            ? $judge->sorted($joined, $this->option('judge-provider') ?: null)
            : array_map(fn (array $record) => ['id' => $record['id'], 'doubt' => null, 'record' => $record], $joined);

        $dataset->write('queue.jsonl', array_map(fn (array $entry) => ['id' => $entry['id'], 'doubt' => $entry['doubt']], $queue));

        $this->line(number_format(count($queue)).' examples · queue sorted '.($triage === 'judge' ? 'by judge doubt (judge scores never become labels)' : 'as exported'));

        foreach (array_slice($queue, 0, (int) $this->option('limit')) as $entry) {
            $this->line(sprintf('  %s  %-10s doubt %s  %s', $entry['id'], $entry['record']['meta']['source'], $entry['doubt'] ?? '-', $this->questionOf($entry['record'])));
        }

        $this->line('Decide with a review file: php artisan dataset:review '.$dataset->name().' --apply=<file>');

        return self::SUCCESS;
    }

    private function apply(Dataset $dataset, array $joined, PersonalDataDetector $pii): int
    {
        $path = str_starts_with((string) $this->option('apply'), '/')
            ? (string) $this->option('apply')
            : base_path((string) $this->option('apply'));

        if (! File::exists($path)) {
            $this->error("No review file at {$path}.");

            return self::FAILURE;
        }

        $result = (new ReviewApplier($pii, (string) config('ai.training.hash_key')))->apply($joined, json_decode(File::get($path), true) ?? []);

        $dataset->write('reviewed.jsonl', $result['records']);

        $dataset->writeJson('review.json', $result['summary']);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->print($result['summary']);

        return self::SUCCESS;
    }

    private function print(array $summary): void
    {
        $this->line(sprintf('reviewed %s · accepted %s · edited %s · rejected %s', number_format($summary['examples']), number_format($summary['accepted']), number_format($summary['edited']), number_format($summary['rejected'])));

        $this->line('rejection reasons: '.($summary['rejection_reasons'] === [] ? 'none' : collect($summary['rejection_reasons'])->map(fn ($count, $reason) => "{$reason} {$count}")->implode(' · ')));

        $this->line(sprintf('double-reviewed %d · agreement %s', $summary['double_reviewed'], $summary['agreement'] === null ? 'n/a' : round($summary['agreement'] * 100).'% (accept/edit/reject)'));
    }

    private function questionOf(array $record): string
    {
        $user = collect($record['prompt'])->where('role', 'user')->last();

        return mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags((string) ($user['content'] ?? ''))), 0, 90, '…');
    }
}
