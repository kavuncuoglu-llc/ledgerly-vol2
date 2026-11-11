<?php

namespace App\Ai\Distillation;

use App\Ai\Context\ContextAssembler;
use App\Ai\Prompts\Collate;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Training\Dataset;
use Closure;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

// Chapter 46, Figure 46-3 and the Build: questions in, the teacher's answers through the gates and the judge, a tenth of what survives queued for a person, and a versioned dataset out, with the teacher, its version and its license basis in the manifest.
// The rows are Chapter 40's: a prompt (the short fine-tuned system message, the passages the teacher was shown, the question) and the teacher's answer as the completion.
class Distiller
{
    public function __construct(private TeacherCheck $teachers, private ConsentedQuestions $consented, private TeacherWrittenQuestions $written, private TeacherAnswerer $answerer, private AnswerFilter $filter) {}

    /**
     * @param  array{consented?: int, teacher-written?: int}  $counts  how many questions of each origin, over all categories
     * @param  list<string>  $categories
     * @param  list<string>  $filters  gates, judge
     * @param  ?Closure(string): void  $progress
     * @return array<string, mixed> the report
     */
    public function distill(string $name, array $categories, string $teacherRole, array $counts, array $filters, float $reviewSample, string $hashKey, bool $patternsOnly = false, ?Closure $progress = null): array
    {
        $teacher = $this->teachers->teacher($teacherRole);

        $dataset = Dataset::named($name);

        if ($dataset->exists('train.jsonl')) {
            throw new InvalidArgumentException("Dataset {$name} exists. A version is never rewritten: name the next one (support-distill-v2).");
        }

        if ($reviewSample < 0 || $reviewSample > 1) {
            throw new InvalidArgumentException('--review-sample is a share between 0 and 1.');
        }

        $questions = $this->questions($categories, $counts, $teacher, $hashKey, $patternsOnly);

        $kept = [];

        $rejected = [];

        $byFilter = ['gates' => 0, 'judge' => 0];

        foreach ($questions as $index => $question) {
            $progress && $progress(sprintf('  %d/%d %s: %s', $index + 1, count($questions), $question->category, mb_strimwidth($question->text, 0, 70, '…')));

            $answer = $this->answerer->answer($question, $teacher);

            $verdict = $this->filter->verdict($question, $answer, in_array('gates', $filters, true), in_array('judge', $filters, true));

            if (! $verdict->kept) {
                $byFilter[$verdict->rejectedBy]++;

                $rejected[] = ['id' => $question->id(), 'category' => $question->category, 'origin' => $question->origin, 'question' => $question->text, 'answer' => $answer->text, 'rejected_by' => $verdict->rejectedBy, 'reason' => $verdict->reason];

                continue;
            }

            $kept[] = ['row' => $this->row($question, $answer), 'meta' => $this->meta($question, $teacher)];
        }

        return $this->write($dataset, $teacher, $categories, $questions, $kept, $rejected, $byFilter, $reviewSample, $filters);
    }

    /** @return list<Question> */
    private function questions(array $categories, array $counts, Teacher $teacher, string $hashKey, bool $patternsOnly): array
    {
        $questions = [];

        foreach ($categories as $position => $category) {
            $consented = $this->share((int) ($counts[Question::CONSENTED] ?? 0), count($categories), $position);

            $written = $this->share((int) ($counts[Question::TEACHER_WRITTEN] ?? 0), count($categories), $position);

            if ($consented > 0) {
                array_push($questions, ...$this->consented->collect($category, $consented, $teacher->region, $hashKey, $patternsOnly));
            }

            if ($written > 0) {
                array_push($questions, ...$this->written->collect($category, $written, $teacher, (int) config('ai.distillation.questions_per_article', 2)));
            }
        }

        $unique = [];

        foreach ($questions as $question) {
            $unique[$question->id()] ??= $question;
        }

        return array_values($unique);
    }

    // The count is for all categories together: split it as evenly as whole questions allow, the remainder to the first categories.
    private function share(int $total, int $categories, int $position): int
    {
        return intdiv($total, $categories) + ($position < $total % $categories ? 1 : 0);
    }

    private function row(Question $question, Answer $answer): array
    {
        $prompt = app(ContextAssembler::class)->system(SupportPrompt::fineTuned())->context($answer->chunks, $question->text)->question($question->text)->build();

        return ['prompt' => Collate::consecutive($prompt->messages), 'completion' => [['role' => 'assistant', 'content' => $answer->text]]];
    }

    private function meta(Question $question, Teacher $teacher): array
    {
        return [
            'id' => $question->id(),
            'source' => 'distilled',
            'origin' => $question->origin,
            'category' => $question->category,
            'subcategory' => basename($question->article),
            'conversation' => $question->conversation,
            'teacher' => $teacher->role,
            'consent' => $question->origin === Question::CONSENTED
                ? 'consented conversations (Chapter 40), questions redacted with surrogates'
                : 'n/a',
            'system_version' => SupportPrompt::FINE_TUNED_VERSION,
        ];
    }

    private function write(Dataset $dataset, Teacher $teacher, array $categories, array $questions, array $kept, array $rejected, array $byFilter, float $reviewSample, array $filters): array
    {
        $share = (float) config('ai.distillation.validation_share', 0.08);

        $train = array_values(array_filter($kept, fn (array $item) => ! $this->isValidation($item['meta']['id'], $share)));

        $validation = array_values(array_filter($kept, fn (array $item) => $this->isValidation($item['meta']['id'], $share)));

        $dataset->write('train.jsonl', array_column($train, 'row'));

        $dataset->write('train.meta.jsonl', array_column($train, 'meta'));

        $dataset->write('validation.jsonl', array_column($validation, 'row'));

        $dataset->write('validation.meta.jsonl', array_column($validation, 'meta'));

        $queue = $this->reviewQueue($kept, $reviewSample);

        $dataset->write('review-queue.jsonl', $queue);

        $dataset->write('rejected.jsonl', $rejected);

        $report = [
            'name' => $dataset->name(),
            'teacher' => $teacher->toArray(),
            'categories' => $categories,
            'filters' => $filters,
            'answers' => count($questions),
            'rejected_by_gates' => $byFilter['gates'],
            'rejected_by_judge' => $byFilter['judge'],
            'kept' => count($kept),
            'queued_for_review' => count($queue),
            'by_origin' => array_count_values(array_column(array_column($kept, 'meta'), 'origin')),
            'by_category' => array_count_values(array_column(array_column($kept, 'meta'), 'category')),
            'train' => count($train),
            'validation' => count($validation),
        ];

        $dataset->writeJson('distill_report.json', $report);

        $dataset->writeJson('manifest.json', $this->manifest($dataset, $teacher, $report, $kept));

        return $report;
    }

    // A tenth of the survivors, at least one when there are any: the ones whose hash sorts first, so the sample is the same on a rerun and is not simply the first ones written.
    /** @param list<array{row: array, meta: array}> $kept */
    public function reviewQueue(array $kept, float $sample): array
    {
        if ($kept === [] || $sample <= 0) {
            return [];
        }

        usort($kept, fn (array $a, array $b) => strcmp(hash('sha256', 'review:'.$a['meta']['id']), hash('sha256', 'review:'.$b['meta']['id'])));

        return array_map(fn (array $item) => ['id' => $item['meta']['id'], 'category' => $item['meta']['category'], 'origin' => $item['meta']['origin'], 'question' => (string) collect($item['row']['prompt'])->where('role', 'user')->last()['content'], 'answer' => $item['row']['completion'][0]['content'], 'decision' => null], array_slice($kept, 0, (int) ceil(count($kept) * $sample)));
    }

    private function isValidation(string $id, float $share): bool
    {
        return hexdec(substr(hash('sha256', 'split:'.$id), 0, 8)) / 0xFFFFFFFF < $share;
    }

    // dataset:show reads the Chapter 40 manifest, so this has its keys; `teacher` is what this chapter adds, and `hosts` is what dataset:allows reads (nothing is allowed anywhere until someone records it).
    private function manifest(Dataset $dataset, Teacher $teacher, array $report, array $kept): array
    {
        $composition = array_count_values(array_column(array_column($kept, 'meta'), 'origin'));

        return [
            'name' => $dataset->name(),
            'published' => now()->toDateString(),
            'app_commit' => $this->commit(),
            'tokenizer' => (string) config('ai.training.tokenizer'),
            'system_version' => SupportPrompt::FINE_TUNED_VERSION,
            'kind' => 'distillation',
            'teacher' => $teacher->toArray(),
            'funnel' => ['exported' => $report['answers'], 'reviewed' => $report['kept'], 'accepted' => $report['kept'], 'edited' => 0, 'rejected' => $report['rejected_by_gates'] + $report['rejected_by_judge'], 'filters' => $report['rejected_by_gates'] + $report['rejected_by_judge'], 'near_duplicates' => 0, 'decontaminated' => 0, 'balance' => 0, 'task_examples' => $report['kept']],
            'removals' => ['review' => [], 'filters' => ['gates' => $report['rejected_by_gates'], 'judge' => $report['rejected_by_judge']], 'near_duplicates' => 0, 'decontaminated' => [], 'balance' => []],
            'counts' => ['by_source' => ['distilled' => $report['kept']], 'by_category' => $report['by_category'], 'total' => $report['kept']],
            'composition' => $composition,
            'split' => ['train' => $report['train'], 'validation' => $report['validation'], 'by' => 'question'],
            'consent' => ['consented' => 'consented conversations (Chapter 40), questions redacted with surrogates', 'teacher-written' => 'n/a'],
            'review' => ['double_reviewed' => 0, 'agreement' => null, 'queued' => $report['queued_for_review']],
            'provenance' => ['filters' => $report['filters'], 'judge_floor' => (int) config('ai.distillation.judge_floor', 4), 'judge' => (string) config('ai.compare.judge_provider', 'local')],
            'stored' => ['region' => $teacher->region, 'disk' => 'local', 'path' => $dataset->name().'/', 'files' => ['train.jsonl', 'validation.jsonl']],
            'hub' => null,
            'hosts' => [],
            'files' => ['train.jsonl' => $dataset->hash('train.jsonl'), 'validation.jsonl' => $dataset->hash('validation.jsonl')],
            'retirement' => null,
        ];
    }

    private function commit(): string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $result->successful()
            ? trim($result->output())
            : 'unknown';
    }
}
