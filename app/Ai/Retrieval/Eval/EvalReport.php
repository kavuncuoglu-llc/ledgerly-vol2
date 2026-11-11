<?php

namespace App\Ai\Retrieval\Eval;

final readonly class EvalReport
{
    /**
     * @param  array<int, float>  $recall  k => recall
     * @param  array<int, float>  $precision  k => precision
     * @param  array<int, array{id: int, question: string, expected: string[], got: string[]}>  $misses
     */
    public function __construct(
        public array $recall,
        public array $precision,
        public float $mrr,
        public int $emptyCorrect,
        public int $emptyTotal,
        public int $answerable,
        public array $misses,
        public float $milliseconds,
    ) {}

    public function recallAt(int $k): float
    {
        return $this->recall[$k] ?? 0.0;
    }

    public function toArray(): array
    {
        return [
            'recall' => $this->recall,
            'precision' => $this->precision,
            'mrr' => $this->mrr,
            'empty_correct' => $this->emptyCorrect,
            'empty_total' => $this->emptyTotal,
            'answerable' => $this->answerable,
            'misses' => $this->misses,
            'ms_per_query' => $this->milliseconds,
        ];
    }
}
