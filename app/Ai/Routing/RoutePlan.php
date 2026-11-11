<?php

namespace App\Ai\Routing;

// Chapter 37, Topic 9: what the cascade will do for one category: the roles to try, cheapest first, and whether an attempt that is not the last must be held back until it has passed its checks.
final readonly class RoutePlan
{
    /** @param  string[]  $chain  provider names, cheapest first, only ones that are set up and allowed */
    public function __construct(
        public string $category,
        public array $chain,
        public bool $buffers,
        public bool $checksRetrieval,
        public ?float $declineBelow,
        public ?float $skipCheapestBelow,
        public ?string $residency = null,
    ) {}

    // Chapter 44, Topic 7: a canary customer is served by the candidate where the route names the role it replaces. The rest of the chain is unchanged.
    public function substituting(string $replaces, string $with): self
    {
        return new self($this->category, array_map(fn (string $role) => $role === $replaces ? $with : $role, $this->chain), $this->buffers, $this->checksRetrieval, $this->declineBelow, $this->skipCheapestBelow, $this->residency);
    }

    // A residency tenant whose route has no role in their region: the answer is a person, never a role somewhere else.
    public function handsToAPerson(): bool
    {
        return $this->chain === [];
    }

    public function chainWithoutCheapest(): array
    {
        return count($this->chain) > 1
            ? array_slice($this->chain, 1)
            : $this->chain;
    }

    public function chainFor(float $bestScore): array
    {
        if ($this->skipCheapestBelow !== null && $bestScore < $this->skipCheapestBelow) {
            return $this->chainWithoutCheapest();                                  // retrieval found little: the question is hard, or the help center does not cover it
        }

        return $this->chain;
    }

    public function declines(float $bestScore): bool
    {
        return $this->checksRetrieval
            && $this->declineBelow !== null
            && $bestScore < $this->declineBelow;
    }
}
