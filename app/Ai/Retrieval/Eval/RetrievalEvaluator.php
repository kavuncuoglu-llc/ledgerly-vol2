<?php

namespace App\Ai\Retrieval\Eval;

use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;

final class RetrievalEvaluator
{
    public function __construct(private Retriever $retriever) {}

    /**
     * @param  GoldenQuestion[]  $questions
     * @param  int[]  $cutoffs
     */
    public function run(array $questions, RetrievalScope $scope, array $cutoffs = [1, 3, 5]): EvalReport
    {
        $deepest = max($cutoffs);

        $hits = array_fill_keys($cutoffs, 0);
        $precision = array_fill_keys($cutoffs, 0.0);
        $reciprocal = 0.0;
        $answerable = 0;
        $emptyCorrect = 0;
        $emptyTotal = 0;
        $misses = [];
        $started = microtime(true);

        foreach ($questions as $question) {
            $articles = array_map(
                fn ($chunk) => $chunk->article(),
                $this->retriever->retrieve($question->question, $scope, $deepest),
            );

            if ($question->expectsNothing()) {
                $emptyTotal++;
                $emptyCorrect += $articles === [] ? 1 : 0;

                continue;
            }

            $answerable++;

            $firstRank = $this->firstRank($articles, $question->articles);

            $reciprocal += $firstRank === null ? 0.0 : 1 / $firstRank;

            foreach ($cutoffs as $k) {
                $top = array_slice($articles, 0, $k);

                $correct = count(array_filter($top, fn (string $article) => in_array($article, $question->articles, true)));

                $hits[$k] += $firstRank !== null && $firstRank <= $k ? 1 : 0;
                $precision[$k] += $top === [] ? 0.0 : $correct / count($top);
            }

            if ($firstRank === null || $firstRank > $deepest) {
                $misses[] = ['id' => $question->id, 'question' => $question->question, 'expected' => $question->articles, 'got' => array_slice($articles, 0, 3)];
            }
        }

        $divide = fn (float $sum) => $answerable === 0 ? 0.0 : round($sum / $answerable, 4);

        return new EvalReport(
            recall: array_map(fn (int $count) => $divide($count), $hits),
            precision: array_map(fn (float $sum) => $divide($sum), $precision),
            mrr: $divide($reciprocal),
            emptyCorrect: $emptyCorrect,
            emptyTotal: $emptyTotal,
            answerable: $answerable,
            misses: $misses,
            milliseconds: count($questions) === 0 ? 0.0 : (microtime(true) - $started) * 1000 / count($questions),
        );
    }

    private function firstRank(array $retrieved, array $expected): ?int
    {
        foreach ($retrieved as $index => $article) {
            if (in_array($article, $expected, true)) {
                return $index + 1;
            }
        }

        return null;
    }
}
