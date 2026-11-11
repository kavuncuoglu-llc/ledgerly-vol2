<?php

namespace App\Ai\Privacy;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

// Chapter 34, Topic 5: deterministic per conversation, encrypted at rest, never in a prompt. The model sees "<EMAIL_1>"; the customer sees the address.
final class Vault
{
    public const TYPES = ['CARD', 'SSN', 'EMAIL', 'PHONE', 'IBAN', 'PERSON', 'ADDRESS'];

    private ?array $loaded = null;

    public function __construct(
        private VaultStore $store,
        private string $key,
        private int $ttlSeconds,
    ) {}

    public static function keyFor(int|string $conversationId): string
    {
        return "vault:{$conversationId}";
    }

    /** @param Span[] $spans in text order */
    public function tokenize(string $text, array $spans): string
    {
        foreach (array_reverse($spans) as $span) {                          // right to left, so offsets stay valid
            $placeholder = $this->placeholderFor($span->type, $span->text);

            $text = substr_replace($text, $placeholder, $span->offset, $span->length);
        }

        return $text;
    }

    public function restore(string $text): string
    {
        $map = $this->map();

        if ($map === []) {
            return $text;
        }

        return (string) preg_replace_callback(self::tolerantPattern(), function (array $hit) use ($map) {
            $type = ($hit['type'] ?? '') !== ''
                ? $hit['type']
                : $hit['type2'];

            $number = ($hit['number'] ?? '') !== ''
                ? $hit['number']
                : $hit['number2'];

            $name = strtoupper($type).'_'.(int) $number;

            if (! array_key_exists($name, $map)) {
                return $hit[0];                                             // not ours: leave it alone
            }

            if ($hit[0] !== "<{$name}>") {
                Log::warning('redaction.placeholder_mangled', ['placeholder' => $hit[0], 'expected' => "<{$name}>"]);
            }

            return $map[$name];
        }, $text);
    }

    // Arguments a tool declared it accepts vault references for: a placeholder anywhere in the values becomes the real value, and only here.
    public function restoreArguments(array $arguments): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => $this->restore($value),
            is_array($value) => $this->restoreArguments($value),
            default => $value,
        }, $arguments);
    }

    public function forget(): void
    {
        $this->store->forget($this->key);

        $this->loaded = [];
    }

    public function isEmpty(): bool
    {
        return $this->map() === [];
    }

    // The tolerant form: "<EMAIL_1>", "<Email 1>", "<email-1>" and a bare "EMAIL_1".
    public static function tolerantPattern(): string
    {
        $types = implode('|', self::TYPES);

        return '/<\s*(?<type>'.$types.')[\s_\-]*(?<number>\d+)\s*>|(?<![A-Za-z0-9_])(?<type2>'.$types.')_(?<number2>\d+)(?![A-Za-z0-9_])/i';
    }

    private function placeholderFor(string $type, string $value): string
    {
        $map = $this->map();

        $existing = array_search($value, $map, true);

        if ($existing !== false) {
            return "<{$existing}>";                                          // same value, same placeholder
        }

        $name = $type.'_'.(count(array_filter(array_keys($map), fn ($k) => str_starts_with($k, "{$type}_"))) + 1);

        $map[$name] = $value;

        $this->store->put($this->key, Crypt::encryptString(json_encode($map)), $this->ttlSeconds);

        $this->loaded = $map;

        return "<{$name}>";
    }

    /** @return array<string, string> */
    private function map(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = $this->store->get($this->key);

        return $this->loaded = $stored === null
            ? []
            : (array) json_decode(Crypt::decryptString($stored), true);
    }
}
