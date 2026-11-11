<?php

namespace App\Ai\Support;

use App\Ai\Schemas\Category;

final readonly class Route
{
    public const AUTOMATE = 'automate';

    public const HUMAN = 'human';

    public const DECLINE = 'decline';

    /**
     * @param  string[]|null  $tools  the only tools this request may use; null means all of them
     * @param  string[]  $candidates
     */
    private function __construct(
        public string $kind,
        public ?Category $category = null,
        public float $agreement = 1.0,
        public ?array $tools = null,
        public ?string $area = null,
        public string $model = 'local-8b',
        public bool $cache = false,
        public ?int $escalateAfterRounds = null,
        public ?string $reason = null,
        public array $candidates = [],
        public array $votes = [],
    ) {}

    public static function make(Category $category, float $agreement, array $tools, ?string $area, string $model = 'local-8b', bool $cache = false, ?int $escalateAfterRounds = null): self
    {
        return new self(self::AUTOMATE, $category, $agreement, $tools, $area, $model, $cache, $escalateAfterRounds);
    }

    public static function human(string $reason, array $candidates, float $agreement = 0.0, ?Category $category = null): self
    {
        return new self(self::HUMAN, $category, $agreement, reason: $reason, candidates: $candidates);
    }

    public static function decline(float $agreement = 1.0, ?Category $category = Category::Other): self
    {
        return new self(self::DECLINE, $category, $agreement, [], reason: 'not a Ledgerly support request');
    }

    public static function fallback(?string $reason = null): self
    {
        return new self(self::AUTOMATE, null, 0.0, null, reason: $reason);              // the router could not tell: every tool, every article
    }

    public function withVotes(array $votes): self
    {
        return new self(...[...get_object_vars($this), 'votes' => $votes]);
    }

    public function isHuman(): bool
    {
        return $this->kind === self::HUMAN;
    }

    public function isDecline(): bool
    {
        return $this->kind === self::DECLINE;
    }
}
