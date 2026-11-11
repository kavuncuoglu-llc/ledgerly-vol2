<?php

namespace App\Evals\Generators;

use App\Ai\Retrieval\RetrievedChunk;
use App\Evals\EvalAnswerer;
use App\Evals\GoldenCase;
use App\Models\Customer;
use App\Models\HelpChunk;
use Illuminate\Support\Str;

// Chapter 29, Figure 29-7: for each chunk a model writes a question that the chunk answers, so the chunk is the expected citation by construction. Questions that copy the chunk's words are dropped, because they make retrieval look better than it is.
final class HelpCenterCases implements Generator
{
    public const VERSION = 1;

    public const MAX_OVERLAP = 0.6;

    public function __construct(private QuestionWriter $writer) {}

    public function name(): string
    {
        return 'help-center';
    }

    public function generate(int $count): iterable
    {
        $chunks = HelpChunk::query()
            ->whereNull('tenant_id')
            ->whereNotNull('embedding')
            ->where('kind', 'parent')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (HelpChunk $chunk) => crc32('help-center:'.$chunk->chunk_key))
            ->values();

        $made = 0;

        foreach ($chunks as $chunk) {
            if ($made >= $count) {
                return;
            }

            $question = $this->writer->ask('Write a question that the following help-center passage answers. Do not reuse its sentences.', Str::limit($chunk->content, 1200));

            if (self::overlap($question, $chunk->content) > self::MAX_OVERLAP) {
                continue;
            }

            $made++;

            yield new GoldenCase(
                id: 'synthetic-help-'.substr($chunk->chunk_key, 0, 10),
                category: $this->categoryOf($chunk->source),
                source: 'synthetic',
                customer: EvalAnswerer::fixtureKey(Customer::query()->findOrFail(12)),
                question: $question,
                expectedBehavior: 'answer',
                expectedChunks: [RetrievedChunk::evalLabelFor($chunk->source, $chunk->heading_path)],
                referenceAnswer: Str::limit(trim(Str::after($chunk->content, "\n")), 240, ''),
                generator: $this->name().'@'.self::VERSION.' via '.$this->writer->provider(),
            );
        }
    }

    // The share of the question's words that also appear in the chunk: high means the generator copied.
    public static function overlap(string $question, string $text): float
    {
        $words = collect(preg_split('/\W+/u', Str::lower($question), -1, PREG_SPLIT_NO_EMPTY))->filter(fn (string $word) => strlen($word) > 3)->unique();

        if ($words->isEmpty()) {
            return 0.0;
        }

        $haystack = Str::lower($text);

        return $words->filter(fn (string $word) => str_contains($haystack, $word))->count() / $words->count();
    }

    private function categoryOf(string $source): string
    {
        return match (explode('/', Str::after($source, 'help/'))[0]) {
            'billing', 'payments', 'taxes' => 'billing',
            'account', 'security' => 'account',
            'troubleshooting' => 'bug',
            'reminders', 'recurring' => 'policy',
            default => 'howto',
        };
    }
}
