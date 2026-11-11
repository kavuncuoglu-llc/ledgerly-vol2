<?php

namespace App\Ai\Privacy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Chapter 34, Topic 4: named-entity recognition in the privacy profile's Presidio service. A failure closes the door for a short cool-down, so a dead service costs one timeout, not one per message.
final class PresidioClient implements Ner
{
    private const DOWN_KEY = 'ledgerly.presidio.down';

    private const UP_KEY = 'ledgerly.presidio.up';

    /** @var array<string, array> */
    private array $memo = [];

    public function __construct(
        private string $url,
        private float $connectTimeout = 1.0,
        private float $timeout = 5.0,
        private float $threshold = 0.5,
        private int $cooldownSeconds = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            url: (string) config('ai.privacy.presidio_url'),
            connectTimeout: (float) config('ai.privacy.connect_timeout'),
            timeout: (float) config('ai.privacy.timeout'),
            threshold: (float) config('ai.privacy.score_threshold'),
            cooldownSeconds: (int) config('ai.privacy.cooldown_seconds'),
        );
    }

    public function available(): bool
    {
        if ($this->url === '') {
            return false;
        }

        if (Cache::has(self::DOWN_KEY)) {
            return false;
        }

        return Cache::remember(self::UP_KEY, 15, fn () => $this->healthy());
    }

    public function find(string $text): array
    {
        if ($this->url === '') {
            throw new NerUnavailable('PRESIDIO_URL is not set.');
        }

        $key = sha1($text);

        $spans = $this->memo[$key] ??= $this->analyze($text);

        if (count($this->memo) > 200) {
            $this->memo = [];
        }

        return $spans;
    }

    private function analyze(string $text): array
    {
        if (Cache::has(self::DOWN_KEY)) {
            throw new NerUnavailable('Presidio did not answer a moment ago; not trying again until the cool-down ends.');
        }

        try {
            $response = Http::baseUrl($this->url)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('/pii/analyze', ['text' => $text, 'score_threshold' => $this->threshold]);
        } catch (ConnectionException $e) {
            throw $this->markDown('Presidio is not reachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw $this->markDown("Presidio answered {$response->status()}.");
        }

        return array_map(fn (array $span) => $this->toSpan($text, $span), (array) $response->json('spans', []));
    }

    private function healthy(): bool
    {
        try {
            return Http::baseUrl($this->url)->connectTimeout($this->connectTimeout)->timeout($this->timeout)->get('/health')->successful();
        } catch (ConnectionException) {
            Cache::put(self::DOWN_KEY, true, $this->cooldownSeconds);

            return false;
        }
    }

    // Python counts characters, substr_replace counts bytes.
    private function toSpan(string $text, array $span): Span
    {
        $start = (int) $span['start'];
        $end = (int) $span['end'];

        $value = mb_substr($text, $start, $end - $start);

        return new Span(
            type: (string) $span['type'],
            offset: strlen(mb_substr($text, 0, $start)),
            length: strlen($value),
            text: $value,
        );
    }

    private function markDown(string $reason): NerUnavailable
    {
        Cache::put(self::DOWN_KEY, true, $this->cooldownSeconds);
        Cache::forget(self::UP_KEY);

        return new NerUnavailable($reason);
    }
}
