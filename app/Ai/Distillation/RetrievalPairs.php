<?php

namespace App\Ai\Distillation;

use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Training\SurrogateRedactor;
use App\Evals\Generators\QuestionWriter;
use App\Models\Conversation;
use App\Models\HelpChunk;
use App\Models\MessageSource;
use Closure;
use Illuminate\Support\Facades\File;
use Throwable;

// Chapter 46, Topic 6: the embedding model's training data is pairs, a question and the chunk that answers it. Two sources: resolved conversations whose answer cited a chunk and whose customer confirmed it helped (real wording, so Chapter 40's consent and residency rules, redaction with surrogates, and a run in the data's region),
// and the teacher's questions for every shared help-center chunk (full coverage, no customer data). The corpus the held-out questions are ranked against is written beside them (`chunks.jsonl`: the text each chunk is embedded as). Chunks that were retrieved but not cited for a question are its hard negatives. The held-out set is the golden retrieval questions: never used for training.
class RetrievalPairs
{
    public function __construct(private SurrogateRedactor $redactor, private PersonalDataDetector $pii) {}

    /**
     * @param  ?Closure(string): void  $progress
     * @return array{pairs: int, consented: int, teacher_written: int, hard_negatives: int, heldout: int}
     */
    public function write(string $directory, ?Teacher $teacher, int $perChunk, ?int $chunkLimit, string $goldenPath, string $hashKey, ?string $region, bool $patternsOnly, ?Closure $progress = null): array
    {
        File::ensureDirectoryExists($directory);

        $consented = $this->consented($hashKey, $region, $patternsOnly);

        $written = $teacher === null
            ? []
            : $this->teacherWritten($teacher, $perChunk, $chunkLimit, $progress);

        $pairs = [...$consented, ...$written];

        $this->put($directory.'/pairs.jsonl', $pairs);

        $heldout = $this->heldout($goldenPath);

        $this->put($directory.'/heldout.jsonl', $heldout);

        $this->put($directory.'/chunks.jsonl', HelpChunk::query()->whereNull('tenant_id')->whereNotNull('embedding')->orderBy('id')->get()->map(fn (HelpChunk $chunk) => ['id' => $chunk->id, 'text' => $this->text($chunk)])->all());     // the corpus the evaluator ranks: what is embedded

        return ['pairs' => count($pairs), 'consented' => count($consented), 'teacher_written' => count($written), 'hard_negatives' => count(array_filter($pairs, fn (array $pair) => ! empty($pair['negative']))), 'heldout' => count($heldout)];
    }

    /** @return list<array> */
    private function consented(string $hashKey, ?string $region, bool $patternsOnly): array
    {
        $pairs = [];

        Conversation::query()->eligibleForTraining($region)->withPositiveSignal()->lazyById(200)->each(function (Conversation $conversation) use ($hashKey, $patternsOnly, &$pairs) {
            foreach ($conversation->answeredTurns() as $turn) {
                $cited = MessageSource::withoutGlobalScopes()->where('message_id', $turn->id)->whereNotNull('help_chunk_id')->pluck('help_chunk_id')->all();

                if ($cited === []) {
                    continue;
                }

                $positive = HelpChunk::query()->find($cited[0]);

                if ($positive === null || $positive->tenant_id !== null) {
                    continue;                                                       // a tenant's own passage is theirs: only shared help-center chunks are training data
                }

                $retrieved = collect($turn->retrieved ?? [])->pluck('id')->diff($cited)->take(1)->first();

                $negative = $retrieved === null
                    ? null
                    : HelpChunk::query()->whereNull('tenant_id')->find($retrieved);

                $pairs[] = array_filter([
                    'anchor' => $this->redacted((string) $turn->question, $conversation, $turn->seq, $hashKey, $patternsOnly),
                    'positive' => $this->text($positive),
                    'negative' => $negative === null ? null : $this->text($negative),
                    'origin' => 'consented',
                    'chunk' => $positive->id,
                ]);
            }
        });

        return $pairs;
    }

    /** @return list<array> */
    private function teacherWritten(Teacher $teacher, int $perChunk, ?int $limit, ?Closure $progress): array
    {
        $writer = new QuestionWriter($teacher->role);

        $pairs = [];

        $chunks = HelpChunk::query()->whereNull('tenant_id')->orderBy('id')->when($limit !== null, fn ($q) => $q->limit($limit))->get();

        foreach ($chunks as $index => $chunk) {
            $progress && $progress(sprintf('  chunk %d/%d %s', $index + 1, $chunks->count(), $chunk->source));

            for ($i = 0; $i < $perChunk; $i++) {
                try {
                    $question = $writer->ask('Write a question a customer might ask that the passage below answers. Use the customer\'s words, not the article\'s headings.', "Help-center article {$chunk->source}, section \"{$chunk->heading}\":\n{$chunk->content}");
                } catch (Throwable) {
                    continue;
                }

                $pairs[] = ['anchor' => $question, 'positive' => $this->text($chunk), 'origin' => 'teacher-written', 'chunk' => $chunk->id];
            }
        }

        return $pairs;
    }

    // The held-out questions: each golden retrieval question with the chunks of its expected articles under the expected headings (every chunk of the article when no heading matches).
    /** @return list<array{id: int, question: string, relevant: list<int>}> */
    private function heldout(string $goldenPath): array
    {
        if (! is_file($goldenPath)) {
            return [];
        }

        $rows = [];

        foreach (file($goldenPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $case = json_decode($line, true);

            $chunks = HelpChunk::query()->whereNull('tenant_id')->get()->filter(fn (HelpChunk $chunk) => in_array(Categories::pathOf((string) $chunk->source), (array) ($case['articles'] ?? []), true));

            $matching = $chunks->filter(fn (HelpChunk $chunk) => collect((array) ($case['headings'] ?? []))->contains(fn (string $heading) => str_contains(mb_strtolower((string) $chunk->heading), mb_strtolower($heading))));

            $relevant = ($matching->isEmpty() ? $chunks : $matching)->pluck('id')->values()->all();

            if ($relevant !== []) {
                $rows[] = ['id' => (int) $case['id'], 'question' => (string) $case['question'], 'relevant' => $relevant];
            }
        }

        return $rows;
    }

    private function text(HelpChunk $chunk): string
    {
        return $chunk->context_header
            ? $chunk->context_header."\n\n".$chunk->content                  // as help:ingest and the worker embed it: the passage with its context header
            : $chunk->content;
    }

    private function redacted(string $text, Conversation $conversation, int $seq, string $key, bool $patternsOnly): string
    {
        $map = [];

        $this->redactor->startExample(hash_hmac('sha256', (string) $conversation->id, $key).':pairs:'.$seq);

        return $this->redactor->redact($text, $patternsOnly ? $this->pii->findPatterns($text) : $this->pii->find($text), $map);
    }

    /** @param  list<array>  $rows */
    private function put(string $path, array $rows): void
    {
        File::put($path, implode('', array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", $rows)));
    }
}
