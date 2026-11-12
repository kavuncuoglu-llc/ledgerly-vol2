<?php

namespace App\Ai\Doctor;

// Chapter 50, Topic 5: one local model, the digest its server reports now, and whether that is the one on record.
final readonly class LocalDigestRow
{
    public const OK = 'ok';

    public const CHANGED = 'changed';

    public const UNRECORDED = 'unrecorded';

    public const UNREACHABLE = 'unreachable';

    public const MISSING = 'missing';

    public function __construct(
        public string $role,
        public string $model,
        public ?string $digest,
        public string $status,
        public string $text,
    ) {}

    public function line(): string
    {
        $short = $this->digest === null
            ? 'digest —'
            : 'digest sha256:'.substr(str_replace('sha256:', '', $this->digest), 0, 4).'…';

        $mark = match ($this->status) {
            self::OK => '✓',
            self::UNRECORDED => '!',
            default => '✗',
        };

        return sprintf('%-17s %-28s %-20s %s %s', $this->role, $this->model, $short, $mark, $this->text);
    }

    public function isProblem(): bool
    {
        return in_array($this->status, [self::CHANGED, self::MISSING], true);
    }
}
