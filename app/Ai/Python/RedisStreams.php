<?php

namespace App\Ai\Python;

use Illuminate\Support\Facades\Redis;
use Predis\ClientInterface;
use Throwable;

// Raw commands, because the argument order of xadd() differs between Predis and phpredis, and the stream names must reach Python exactly as written (no key prefix).
final class RedisStreams implements Streams
{
    public function __construct(private string $connection = 'default') {}

    public static function fromConfig(): self
    {
        return new self((string) config('ai.python.redis_connection', 'default'));
    }

    public function add(string $stream, array $fields): string
    {
        $arguments = ['XADD', $stream, '*'];

        foreach ($fields as $name => $value) {
            $arguments[] = (string) $name;
            $arguments[] = (string) $value;
        }

        return (string) $this->command($arguments);
    }

    public function ensureGroup(string $stream, string $group): void
    {
        try {
            $this->command(['XGROUP', 'CREATE', $stream, $group, '0', 'MKSTREAM']);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    public function readGroup(string $stream, string $group, string $consumer, int $count = 8, int $blockMilliseconds = 0): array
    {
        $arguments = ['XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', (string) $count];

        if ($blockMilliseconds > 0) {
            array_push($arguments, 'BLOCK', (string) $blockMilliseconds);
        }

        array_push($arguments, 'STREAMS', $stream, '>');

        $reply = $this->command($arguments);

        if (! is_array($reply)) {
            return [];
        }

        $entries = [];

        foreach ($reply as [$name, $streamEntries]) {
            foreach ($streamEntries as [$id, $flat]) {
                $entries[] = ['id' => (string) $id, 'fields' => $this->pairs($flat)];
            }
        }

        return $entries;
    }

    public function ack(string $stream, string $group, string $id): void
    {
        $this->command(['XACK', $stream, $group, $id]);
    }

    public function length(string $stream): int
    {
        return (int) $this->command(['XLEN', $stream]);
    }

    public function pending(string $stream, string $group): int
    {
        try {
            $summary = $this->command(['XPENDING', $stream, $group]);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'NOGROUP')) {
                throw $e;
            }

            return 0;                                                  // no worker has ever read this stream
        }

        return is_array($summary)
            ? (int) $summary[0]
            : 0;
    }

    private function pairs(array $flat): array
    {
        $fields = [];

        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $fields[(string) $flat[$i]] = (string) $flat[$i + 1];
        }

        return $fields;
    }

    private function command(array $arguments): mixed
    {
        $client = Redis::connection($this->connection)->client();

        if ($client instanceof ClientInterface) {
            return $client->executeRaw($arguments);
        }

        return $client->rawCommand(...$arguments);
    }
}
