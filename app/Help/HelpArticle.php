<?php

namespace App\Help;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class HelpArticle
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    private const FRONT_MATTER_PATTERN = '/\A---\R(.*?)\R---[ \t]*(?:\R|\z)(.*)\z/s';

    public function __construct(
        public readonly string $category,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly CarbonImmutable $updated,
        public readonly string $body,
        public readonly array $frontMatter = [],
    ) {}

    public static function basePath(): string
    {
        return rtrim((string) config('ledgerly.help_path'), '/\\');
    }

    public static function all(): Collection
    {
        return collect(self::categorySlugs())
            ->flatMap(fn (string $category) => self::inCategory($category))
            ->values();
    }

    public static function inCategory(string $category): Collection
    {
        if (! self::isValidSlug($category)) {
            return collect();
        }

        $directory = self::basePath().'/'.$category;

        if (! is_dir($directory)) {
            return collect();
        }

        $files = glob($directory.'/*.md') ?: [];
        sort($files);

        return collect($files)
            ->reject(fn (string $file) => str_starts_with(basename($file), '_'))
            ->map(fn (string $file) => self::fromFile($file, $category))
            ->sortBy(fn (self $article) => Str::lower($article->title))
            ->values();
    }

    public static function find(string $category, string $slug): ?self
    {
        if (! self::isValidSlug($category)) {
            return null;
        }

        if (! self::isValidSlug($slug)) {
            return null;
        }

        $file = self::basePath().'/'.$category.'/'.$slug.'.md';

        if (! is_file($file)) {
            return null;
        }

        return self::fromFile($file, $category);
    }

    public static function categories(): Collection
    {
        return collect(self::categorySlugs())
            ->map(fn (string $category) => [
                'slug' => $category,
                'label' => self::categoryLabel($category),
                'articles' => self::inCategory($category),
            ])
            ->filter(fn (array $category) => $category['articles']->isNotEmpty())
            ->values();
    }

    public static function categoryLabel(string $category): string
    {
        return Str::headline($category);
    }

    public static function parse(string $category, string $slug, string $contents, ?CarbonImmutable $fallbackDate = null): self
    {
        [$frontMatter, $body] = self::splitFrontMatter($contents);

        return new self(
            category: $category,
            slug: $slug,
            title: self::stringValue($frontMatter['title'] ?? '') ?: Str::headline($slug),
            summary: self::stringValue($frontMatter['summary'] ?? ''),
            updated: self::dateValue($frontMatter['updated'] ?? null, $fallbackDate),
            body: ltrim($body, "\r\n"),
            frontMatter: $frontMatter,
        );
    }

    public function source(): string
    {
        return 'help/'.$this->category.'/'.$this->slug.'.md';
    }

    public function path(): string
    {
        return self::basePath().'/'.$this->category.'/'.$this->slug.'.md';
    }

    public function url(): string
    {
        return route('help.show', [$this->category, $this->slug], absolute: false);
    }

    public function categoryLabelText(): string
    {
        return self::categoryLabel($this->category);
    }

    public function declaresMatchingCategory(): bool
    {
        return ($this->frontMatter['category'] ?? null) === $this->category;
    }

    public function missingFrontMatterKeys(): array
    {
        return array_values(array_filter(
            ['title', 'category', 'summary', 'updated'],
            fn (string $key) => ! isset($this->frontMatter[$key]) || $this->frontMatter[$key] === '',
        ));
    }

    public function html(): string
    {
        return (string) Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'html_class' => 'heading-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'symbol' => '#',
                'aria_hidden' => true,
            ],
        ], [new HeadingPermalinkExtension]);
    }

    public function headings(): array
    {
        preg_match_all('/^(#{2,3})\s+(.+?)\s*#*\s*$/m', $this->withoutCodeFences($this->body), $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [
            'level' => strlen($match[1]),
            'text' => $match[2],
            'id' => Str::slug($match[2]),
        ], $matches);
    }

    private static function fromFile(string $file, string $category): self
    {
        $fallbackDate = CarbonImmutable::createFromTimestamp(filemtime($file));

        return self::parse($category, basename($file, '.md'), (string) file_get_contents($file), $fallbackDate);
    }

    private static function categorySlugs(): array
    {
        $base = self::basePath();

        if (! is_dir($base)) {
            return [];
        }

        $directories = glob($base.'/*', GLOB_ONLYDIR) ?: [];

        $slugs = array_map('basename', $directories);
        $slugs = array_filter($slugs, fn (string $slug) => self::isValidSlug($slug));
        sort($slugs);

        return array_values($slugs);
    }

    private static function isValidSlug(string $value): bool
    {
        return (bool) preg_match(self::SLUG_PATTERN, $value);
    }

    private static function splitFrontMatter(string $contents): array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");

        if (! preg_match(self::FRONT_MATTER_PATTERN, $contents, $matches)) {
            return [[], $contents];
        }

        try {
            $parsed = Yaml::parse($matches[1], Yaml::PARSE_DATETIME);
        } catch (ParseException) {
            return [[], $matches[2]];
        }

        return [
            is_array($parsed) ? $parsed : [],
            $matches[2],
        ];
    }

    private static function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function dateValue(mixed $value, ?CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value)->startOfDay();
            } catch (Throwable) {
                return $fallback ?? CarbonImmutable::createFromTimestamp(0);
            }
        }

        return $fallback ?? CarbonImmutable::createFromTimestamp(0);
    }

    private function withoutCodeFences(string $markdown): string
    {
        return (string) preg_replace('/^(```|~~~).*?^\1/ms', '', $markdown);
    }
}
