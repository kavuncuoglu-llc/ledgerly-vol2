<?php

namespace App\Ai\Deprecations;

use Carbon\CarbonImmutable;

// Chapter 50, Topic 4: one row of config/ai-deprecations.php with its dates resolved against a day.
final readonly class DeprecationEntry
{
    public const KINDS = ['model', 'finetune', 'api', 'package', 'local'];

    public const RETIRES = 'retires';

    public const ABANDONED = 'abandoned';

    public const REVIEW = 'review';

    public const FOLLOWS = 'follows';

    public function __construct(
        public string $kind,
        public string $id,
        public string $state,
        public ?CarbonImmutable $date,
        public ?int $daysLeft,
        public string $replacement = '',
        public string $read = '',
        public string $source = '',
        public string $follows = '',
        public bool $example = false,
    ) {}

    public function isDated(): bool
    {
        return $this->date !== null
            && in_array($this->state, [self::RETIRES, self::FOLLOWS], true);
    }

    public function isRoutable(): bool
    {
        return in_array($this->kind, ['model', 'finetune', 'api'], true);
    }

    public function isRetired(): bool
    {
        if (! $this->isDated()) {
            return false;
        }

        return $this->daysLeft <= 0;
    }

    public function band(array $alertDays): ?int
    {
        if (! $this->isDated()) {
            return null;
        }

        $sorted = $alertDays;

        sort($sorted);

        foreach ($sorted as $days) {
            if ($this->daysLeft <= $days) {
                return $days;
            }
        }

        return null;
    }

    public function whenText(): string
    {
        return match ($this->state) {
            self::ABANDONED => 'abandoned',
            self::REVIEW => 'review '.$this->date?->toDateString(),
            default => (string) $this->date?->toDateString(),
        };
    }
}
