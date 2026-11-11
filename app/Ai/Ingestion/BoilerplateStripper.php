<?php

namespace App\Ai\Ingestion;

use App\Help\HelpArticle;

final class BoilerplateStripper
{
    public function __construct(private array $patterns) {}

    public static function fromConfig(): self
    {
        return new self(config('ai.retrieval.boilerplate', []));
    }

    public function count(): int
    {
        return count($this->patterns);
    }

    public function strip(HelpArticle $article): HelpArticle
    {
        $body = $article->body;

        foreach ($this->patterns as $pattern) {
            $body = preg_replace($pattern, '', $body) ?? $body;
        }

        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        return new HelpArticle(
            category: $article->category,
            slug: $article->slug,
            title: $article->title,
            summary: $article->summary,
            updated: $article->updated,
            body: trim($body)."\n",
            frontMatter: $article->frontMatter,
        );
    }
}
