<?php

namespace App\Ai\Support;

// What the router already knows when it picks a model tier (Chapter 20, Topic 5): the classifier's agreement, the shape of the question, how many agentic rounds failed, and whether a person flagged it.
final readonly class Signals
{
    public function __construct(
        public float $agreement = 1.0,
        public bool $multiAsk = false,
        public bool $isDispute = false,
        public int $unresolvedRounds = 0,
        public bool $humanFlagged = false,
    ) {}
}
