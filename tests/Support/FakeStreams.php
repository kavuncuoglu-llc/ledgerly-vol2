<?php

namespace Tests\Support;

use App\Ai\Python\Streams;

// An in-memory stand-in for Redis streams: entries, consumer groups, and the pending list. Enough for the PHP side of Chapter 27 without a Redis server.
class FakeStreams implements Streams
{
    /** @var array<string, array<int, array{id: string, fields: array<string, string>}>> */
    public array $entries = [];

    /** @var array<string, array<string, array{delivered: int, pending: array<string, true>}>> */
    private array $groups = [];

    private int $sequence = 0;

    public function add(string $stream, array $fields): string
    {
        $id = (++$this->sequence).'-0';

        $this->entries[$stream][] = ['id' => $id, 'fields' => $fields];

        return $id;
    }

    public function ensureGroup(string $stream, string $group): void
    {
        $this->entries[$stream] ??= [];

        $this->groups[$stream][$group] ??= ['delivered' => 0, 'pending' => []];
    }

    public function readGroup(string $stream, string $group, string $consumer, int $count = 8, int $blockMilliseconds = 0): array
    {
        $this->ensureGroup($stream, $group);

        $delivered = $this->groups[$stream][$group]['delivered'];

        $batch = array_slice($this->entries[$stream], $delivered, $count);

        $this->groups[$stream][$group]['delivered'] += count($batch);

        foreach ($batch as $entry) {
            $this->groups[$stream][$group]['pending'][$entry['id']] = true;
        }

        return $batch;
    }

    public function readPending(string $stream, string $group, string $consumer, int $count = 8): array
    {
        $pending = array_keys($this->groups[$stream][$group]['pending'] ?? []);

        return array_slice(array_values(array_filter($this->entries[$stream] ?? [], fn (array $entry) => in_array($entry['id'], $pending, true))), 0, $count);
    }

    public function ack(string $stream, string $group, string $id): void
    {
        unset($this->groups[$stream][$group]['pending'][$id]);
    }

    public function length(string $stream): int
    {
        return count($this->entries[$stream] ?? []);
    }

    public function pending(string $stream, string $group): int
    {
        return count($this->groups[$stream][$group]['pending'] ?? []);
    }

    public function decoded(string $stream, int $index = 0): array
    {
        return json_decode($this->entries[$stream][$index]['fields']['task'], true);
    }
}
