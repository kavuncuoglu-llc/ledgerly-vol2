<?php

namespace App\Ai\Parsing;

use App\Help\HelpArticle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Turns a directory of <category>/<file>.{md,html,docx,pdf} into articles: every source normalized to Markdown, then the one chunker.
final class DocumentArticles
{
    public function __construct(private DocumentParser $parser) {}

    /** @return Collection<int, HelpArticle> */
    public function from(string $directory): Collection
    {
        $articles = collect();

        foreach (glob(rtrim($directory, '/').'/*', GLOB_ONLYDIR) ?: [] as $categoryDirectory) {
            $category = basename($categoryDirectory);

            foreach (glob($categoryDirectory.'/*') ?: [] as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (str_starts_with(basename($file), '_') || ! is_file($file) || ! $this->parser->supports($extension)) {
                    continue;
                }

                $articles->push($this->article($category, $file));
            }
        }

        return $articles->sortBy(fn (HelpArticle $article) => $article->source())->values();
    }

    private function article(string $category, string $file): HelpArticle
    {
        $slug = Str::slug(pathinfo($file, PATHINFO_FILENAME));

        [$title, $body] = $this->normalize($this->parser->parse($file), $slug);

        return new HelpArticle(
            category: $category,
            slug: $slug,
            title: $title,
            summary: '',
            updated: CarbonImmutable::createFromTimestamp((int) filemtime($file)),
            body: $body,
        );
    }

    /** @return array{0: string, 1: string} title and a body whose headings start at ## */
    private function normalize(string $markdown, string $slug): array
    {
        $markdown = trim($markdown);

        $title = Str::headline($slug);

        if (preg_match('/\A#\s+(.+?)\s*#*\R/', $markdown."\n", $match) === 1) {
            $title = trim($match[1]);

            $markdown = ltrim(substr($markdown, strlen($match[0]) - 1));
        }

        $markdown = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', function (array $heading) {
            $level = min(3, max(2, strlen($heading[1])));                            // the chunker splits on ## and ###

            return str_repeat('#', $level).' '.$heading[2];
        }, $markdown);

        return [$title, $markdown."\n"];
    }
}
