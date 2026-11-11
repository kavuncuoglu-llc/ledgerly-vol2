<?php

namespace App\Ai\Security;

final class Untrusted
{
    private static ?string $token = null;

    public static function token(): string
    {
        return self::$token ??= bin2hex(random_bytes(4));        // one per request; unpredictable
    }

    public static function reset(?string $token = null): void
    {
        self::$token = $token;                                    // a worker calls this between jobs; a test passes a fixed token
    }

    public static function wrap(string $text, string $source): string
    {
        $tag = 'untrusted-'.self::token();

        $clean = self::stripForgedTags($text);

        $source = preg_replace('/[^a-z0-9:_.-]/i', '', $source);

        return "<{$tag} source=\"{$source}\">\n{$clean}\n</{$tag}>";
    }

    public static function stripForgedTags(string $text): string
    {
        do {
            $before = $text;

            $text = preg_replace('/<\s*\/?\s*untrusted[^>]*>?/i', '', $text);    // no forged open or close tags, however spaced; repeated, so a tag built around a removed tag is removed too
        } while ($text !== $before);

        return $text;
    }
}
