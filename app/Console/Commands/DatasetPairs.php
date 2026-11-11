<?php

namespace App\Console\Commands;

use App\Ai\Distillation\RetrievalPairs;
use App\Ai\Distillation\TeacherCheck;
use App\Ai\Distillation\TeacherRefused;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DatasetPairs extends Command
{
    protected $signature = 'dataset:pairs
        {--out=training/data/retrieval : Where pairs.jsonl and heldout.jsonl are written (git-ignored)}
        {--teacher= : The role that writes questions for each shared help-center chunk (default config ai.distillation.teacher); none for consented pairs only}
        {--per-chunk=2 : Questions the teacher writes for each chunk}
        {--limit-chunks= : Only the first N chunks, for a quick trial}
        {--golden=storage/golden/retrieval.jsonl : The held-out questions (never trained on)}
        {--region= : The region this runs in: a tenant with a data residency elsewhere is left out}
        {--patterns-only : Replace cards, emails, phones, IBANs and SSNs only}';

    protected $description = 'Build the embedding model\'s training pairs (cited, confirmed answers and teacher-written questions per chunk, with hard negatives) and its held-out set (Chapter 46, Topic 6)';

    public function handle(RetrievalPairs $pairs, TeacherCheck $teachers): int
    {
        try {
            $role = $this->option('teacher') ?? (string) config('ai.distillation.teacher');

            $teacher = $role === 'none'
                ? null
                : $teachers->teacher($role);

            $report = $pairs->write(
                directory: $this->absolute((string) $this->option('out')),
                teacher: $teacher,
                perChunk: (int) $this->option('per-chunk'),
                chunkLimit: $this->option('limit-chunks') === null ? null : (int) $this->option('limit-chunks'),
                goldenPath: $this->absolute((string) $this->option('golden')),
                hashKey: $this->hashKey(),
                region: $this->option('region') ?: null,
                patternsOnly: (bool) $this->option('patterns-only'),
                progress: fn (string $line) => $this->output->isVerbose() ? $this->line($line) : null,
            );
        } catch (TeacherRefused|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Pairs stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  %d pairs (%d from confirmed answers, %d teacher-written, %d with a hard negative) · %d held-out questions in %s', $report['pairs'], $report['consented'], $report['teacher_written'], $report['hard_negatives'], $report['heldout'], $this->option('out')));

        return self::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function hashKey(): string
    {
        $key = (string) config('ai.training.hash_key');

        if ($key === '') {
            throw new InvalidArgumentException('Set AI_TRAINING_HASH_KEY: consented questions are seeded by a keyed hash of their conversation.');
        }

        return $key;
    }
}
