<?php

namespace App\Ai\Policy;

// Chapter 51, Topic 3: what the policy decided, in a form the model can explain and cannot change. `code` is the rule that decided; `explanation` is a sentence the model may repeat.
final readonly class CreditDecision
{
    public function __construct(
        public bool $eligible,
        public int $maxCents,
        public string $code,
        public string $explanation,
    ) {}

    public function allows(int $amountCents): bool
    {
        return $this->eligible
            && $amountCents >= 1
            && $amountCents <= $this->maxCents;
    }

    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'max_cents' => $this->maxCents,
            'rule' => $this->code,
            'explanation' => $this->explanation,
        ];
    }
}
