<?php

namespace App\Ai\Cache;

use App\Help\HelpArticle;
use App\Models\HelpChunk;
use Throwable;

// Which version of the help center an answer was written against: the version help:ingest stamped on the chunks (Chapter 15), else a hash of the articles on disk
final class HelpVersion
{
    private const REFRESH_SECONDS = 30;

    private ?string $current = null;

    private float $resolvedAt = 0.0;

    public function current(): string
    {
        $configured = config('ledgerly.help_version');

        if ($configured) {
            return substr((string) $configured, 0, 40);
        }

        if ($this->current !== null && microtime(true) - $this->resolvedAt < self::REFRESH_SECONDS) {
            return $this->current;
        }

        $this->resolvedAt = microtime(true);

        return $this->current = $this->ingested() ?? $this->ofArticles();
    }

    public function forget(): void
    {
        $this->current = null;
    }

    public function ofArticles(): string
    {
        $parts = HelpArticle::all()->map(fn (HelpArticle $article) => $article->source().'|'.$article->updated->toDateString().'|'.md5($article->body));

        return substr(sha1($parts->implode("\n")), 0, 12);                  // changes when any article's text or date does
    }

    private function ingested(): ?string
    {
        try {
            $version = HelpChunk::query()
                ->whereNull('tenant_id')
                ->where('help_version', '!=', '')
                ->orderByDesc('id')
                ->value('help_version');
        } catch (Throwable) {
            return null;                                                    // no database yet (a unit test, a fresh checkout before migrate)
        }

        return $version === null
            ? null
            : (string) $version;
    }
}
