<?php

namespace App\Console\Commands;

use App\Ai\Distillation\Categories;
use App\Ai\Distillation\Distiller;
use App\Ai\Distillation\TeacherRefused;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DatasetDistill extends Command
{
    protected $signature = 'dataset:distill
        {name : The new dataset version (support-distill-v1)}
        {--categories=howto,plans,integrations : Areas of the help center (config ai.distillation.categories)}
        {--teacher= : The teacher role (default config ai.distillation.teacher): it must have a recorded distillation_basis}
        {--questions=consented:2000,teacher-written:1500 : How many questions of each origin, over all categories}
        {--filter=gates,judge : What a teacher answer must pass}
        {--review-sample=0.10 : The share of survivors queued for a person}
        {--patterns-only : Replace cards, emails, phones, IBANs and SSNs only (names and addresses need the privacy profile)}';

    protected $description = 'Distill a student\'s training set: questions to a teacher, its answers through the gates and the judge, a tenth queued for review (Chapter 46)';

    public function handle(Distiller $distiller): int
    {
        try {
            $report = $distiller->distill(
                name: (string) $this->argument('name'),
                categories: Categories::validated($this->list('categories')),
                teacherRole: (string) ($this->option('teacher') ?: config('ai.distillation.teacher')),
                counts: $this->counts(),
                filters: $this->filters(),
                reviewSample: (float) $this->option('review-sample'),
                hashKey: $this->hashKey(),
                patternsOnly: (bool) $this->option('patterns-only'),
                progress: fn (string $line) => $this->output->isVerbose() ? $this->line($line) : null,
            );
        } catch (TeacherRefused|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Distillation stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  %s teacher answers · rejected by gates %s · by judge %s · kept %s · %s queued for review', number_format($report['answers']), number_format($report['rejected_by_gates']), number_format($report['rejected_by_judge']), number_format($report['kept']), number_format($report['queued_for_review'])));

        $this->line("  {$report['name']}: train {$report['train']} · validation {$report['validation']} · teacher {$report['teacher']['model']} ({$report['teacher']['distillation_basis']['license']}, reviewed {$report['teacher']['distillation_basis']['reviewed']})");

        $rate = $report['answers'] === 0
            ? 0
            : ($report['rejected_by_gates'] + $report['rejected_by_judge']) / $report['answers'];

        $this->line(sprintf('  the filter rejected %.1f%% of the teacher\'s answers; rejected.jsonl has each with its reason', $rate * 100));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function list(string $option): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach ($this->list('questions') as $part) {
            [$origin, $number] = array_pad(explode(':', $part, 2), 2, '');

            if (! in_array($origin, ['consented', 'teacher-written'], true) || ! ctype_digit($number)) {
                throw new InvalidArgumentException("--questions is consented:<n> and teacher-written:<n>, not [{$part}].");
            }

            $counts[$origin] = (int) $number;
        }

        return $counts;
    }

    /** @return list<string> */
    private function filters(): array
    {
        $filters = $this->list('filter');

        foreach ($filters as $filter) {
            if (! in_array($filter, ['gates', 'judge'], true)) {
                throw new InvalidArgumentException("--filter is gates, judge or both, not [{$filter}].");
            }
        }

        return $filters;
    }

    private function hashKey(): string
    {
        $key = (string) config('ai.training.hash_key');

        if ($key === '') {
            throw new InvalidArgumentException('Set AI_TRAINING_HASH_KEY: the conversation a consented question came from is recorded as a keyed hash, so an erasure request can find it and nobody else can.');
        }

        return $key;
    }
}
