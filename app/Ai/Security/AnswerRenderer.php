<?php

namespace App\Ai\Security;

final class AnswerRenderer
{
    private const MARKDOWN = '/(!?)\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/u';

    private const HTML_IMAGE = '/<img\b[^>]*>/i';

    private const HTML_LINK = '/<a\b[^>]*href\s*=\s*["\']?([^"\'\s>]+)["\']?[^>]*>(.*?)<\/a>/is';

    private const BARE_URL = '#\bhttps?://[^\s<>)\]"\']+#i';

    public function render(string $answer): string
    {
        $text = preg_replace_callback(self::MARKDOWN, fn (array $m) => $this->markdown($m), $answer);

        $text = preg_replace(self::HTML_IMAGE, '', $text);

        $text = preg_replace_callback(self::HTML_LINK, fn (array $m) => $this->allowed($m[1]) ? $m[0] : $m[2], $text);

        $text = preg_replace_callback(self::BARE_URL, fn (array $m) => $this->allowed($m[0]) ? $m[0] : '', $text);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    public function strippedHosts(string $answer): array
    {
        $found = [];

        preg_match_all('#(?:https?:)?//([^/\s:?\#)\]"\'>]+)#i', $answer, $matches);

        foreach ($matches[1] as $host) {
            if (! $this->allowedHost(strtolower($host))) {
                $found[] = strtolower($host);
            }
        }

        return array_values(array_unique($found));
    }

    public function allowed(string $url): bool
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (! isset($parts['host'])) {
            return ! isset($parts['scheme']);                                     // a relative path stays; mailto:, javascript: and data: do not
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        return $this->allowedHost(strtolower($parts['host']));
    }

    public function hosts(): array
    {
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_values(array_filter([
            ...(array) config('ai.security.link_hosts', []),
            is_string($own) ? $own : null,
        ]));
    }

    private function markdown(array $m): string
    {
        if ($this->allowed($m[3])) {
            return $m[0];
        }

        return $m[1] === '!'
            ? ''                                                                  // an image that would load on its own, or a link that would be followed: only the words stay
            : $m[2];
    }

    private function allowedHost(string $host): bool
    {
        foreach ($this->hosts() as $allowed) {
            $allowed = strtolower($allowed);

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
