<?php

namespace App\Ai\Python;

// The few Redis stream commands the PHP side of the boundary needs (Chapter 27, Topic 3). Tests bind an in-memory one, so the suite needs no Redis.
interface Streams
{
    /** @param array<string, string> $fields */
    public function add(string $stream, array $fields): string;

    public function ensureGroup(string $stream, string $group): void;

    /** @return array<int, array{id: string, fields: array<string, string>}> */
    public function readGroup(string $stream, string $group, string $consumer, int $count = 8, int $blockMilliseconds = 0): array;

    public function ack(string $stream, string $group, string $id): void;

    public function length(string $stream): int;

    public function pending(string $stream, string $group): int;
}
