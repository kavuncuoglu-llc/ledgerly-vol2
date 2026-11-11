<?php

namespace App\Ai\Doctor;

// One line of ai:doctor: a tick, a warning or a failure, and the sentence that tells you what to do about it.
final readonly class Check
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(public string $status, public string $message) {}

    public static function ok(string $message): self
    {
        return new self(self::OK, $message);
    }

    public static function warn(string $message): self
    {
        return new self(self::WARN, $message);
    }

    public static function fail(string $message): self
    {
        return new self(self::FAIL, $message);
    }

    public function symbol(): string
    {
        return match ($this->status) {
            self::OK => '✓',
            self::WARN => '⚠',
            default => '✗',
        };
    }
}
