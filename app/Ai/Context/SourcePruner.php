<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Tokens\TokenCounter;

// Chapter 37, Topic 6: what retrieval attaches, made smaller. Deduplication drops a chunk whose text another chunk already carries (parent-child chunking retrieves a child and its parent together; two articles can quote one paragraph).
// Trimming sends only the paragraphs of a long chunk that match the question, not the whole chunk. Chapter 35's hard budget still caps the total after both. Every technique can cut too much, so each goes through the golden set before it ships:
// deduplication is on, trimming is off until a golden-set run says it does not hurt (ai.pruning).
final class SourcePruner
{
    private const STOPWORDS = ['the', 'and', 'for', 'how', 'can', 'does', 'what', 'when', 'where', 'why', 'with', 'from', 'that', 'this', 'are', 'was', 'you', 'your', 'our', 'any', 'all', 'not', 'but', 'have', 'has', 'get', 'its', 'into', 'than', 'then', 'they', 'them', 'will', 'would', 'should', 'could'];

    public function __construct(private TokenCounter $counter) {}

    /**
     * @param  RetrievedChunk[]  $chunks  best first
     * @return RetrievedChunk[]
     */
    public function prune(array $chunks, ?string $question = null): array
    {
        if (config('ai.pruning.dedupe', true)) {
            $chunks = $this->deduplicate($chunks);
        }

        if (config('ai.pruning.trim', false) && $question !== null) {
            $chunks = array_map(fn (RetrievedChunk $chunk) => $this->trimmed($chunk, $question), $chunks);
        }

        return $chunks;
    }

    /** @param  RetrievedChunk[]  $chunks */
    private function deduplicate(array $chunks): array
    {
        $texts = array_map(fn (RetrievedChunk $chunk) => $this->normalised($chunk->body()), $chunks);

        $kept = [];

        foreach ($chunks as $index => $chunk) {
            foreach ($chunks as $other => $candidate) {
                if ($other === $index) {
                    continue;
                }

                if ($this->containedIn($texts[$index], $texts[$other], $index, $other)) {
                    continue 2;                                                    // another chunk carries all of this one's text
                }

                if ($other < $index && ! $this->containedIn($texts[$other], $texts[$index], $other, $index) && $this->shingleOverlap($texts[$index], $texts[$other]) >= (float) config('ai.pruning.dedupe_similarity', 0.85)) {
                    continue 2;                                                    // a better-ranked chunk says nearly the same
                }
            }

            $kept[] = $chunk;
        }

        return $kept;
    }

    // A is dropped when B (a different chunk) contains it. Identical texts: the better-ranked one stays.
    private function containedIn(string $a, string $b, int $indexA, int $indexB): bool
    {
        if ($a === '' || strlen($a) > strlen($b)) {
            return false;
        }

        if ($a === $b) {
            return $indexB < $indexA;
        }

        return str_contains($b, $a);
    }

    private function shingleOverlap(string $a, string $b): float
    {
        $first = $this->shingles($a);

        $second = $this->shingles($b);

        if ($first === [] || $second === []) {
            return 0.0;
        }

        return count(array_intersect($first, $second)) / min(count($first), count($second));          // the overlap coefficient: how much of the shorter text the longer one repeats
    }

    /** @return string[] */
    private function shingles(string $text): array
    {
        $words = explode(' ', $text);

        $shingles = [];

        for ($i = 0; $i + 4 < count($words); $i++) {
            $shingles[implode(' ', array_slice($words, $i, 5))] = true;
        }

        return array_keys($shingles);
    }

    private function trimmed(RetrievedChunk $chunk, string $question): RetrievedChunk
    {
        $limit = (int) config('ai.pruning.trim_over_tokens', 400);

        $body = $chunk->body();

        if ($this->counter->count($body) <= $limit) {
            return $chunk;
        }

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []), fn (string $paragraph) => $paragraph !== ''));

        if (count($paragraphs) < 2) {
            return $chunk;
        }

        $terms = $this->terms($question);

        $scored = [];

        foreach ($paragraphs as $position => $paragraph) {
            $scored[$position] = count(array_intersect($terms, $this->terms($paragraph)));
        }

        arsort($scored);

        $budget = (int) config('ai.pruning.trim_to_tokens', 250);

        $keep = [];

        $used = 0;

        foreach (array_keys($scored) as $position) {
            $cost = $this->counter->count($paragraphs[$position]);

            if ($used > 0 && $used + $cost > $budget) {
                continue;
            }

            $keep[] = $position;

            $used += $cost;
        }

        sort($keep);

        $heading = strtok($chunk->content, "\n") === $chunk->heading
            ? $chunk->heading."\n"
            : '';

        return new RetrievedChunk($chunk->id, $chunk->source, $chunk->title, $chunk->headingPath, $chunk->heading, $heading.implode("\n\n", array_map(fn (int $position) => $paragraphs[$position], $keep)), $chunk->helpVersion, $chunk->similarity, $chunk->keywordRank, $chunk->score, $chunk->chunkKey, $chunk->tenantId, $chunk->plans, $chunk->matched);
    }

    /** @return string[] */
    private function terms(string $text): array
    {
        preg_match_all('/[a-z0-9%]{3,}/', mb_strtolower($text), $matches);

        return array_values(array_diff(array_unique($matches[0]), self::STOPWORDS));
    }

    private function normalised(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }
}
