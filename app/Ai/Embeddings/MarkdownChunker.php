<?php

namespace App\Ai\Embeddings;

use App\Ai\Tokens\TokenCounter;
use App\Help\HelpArticle;

// Splits an article on its ## and ### headings and keeps "Title › Heading" at the top of every chunk.
// Sections under $minTokens are merged with the next one; sections over $maxTokens are split on paragraphs.
final class MarkdownChunker
{
    public const SEPARATOR = ' › ';

    public function __construct(
        private TokenCounter $counter,
        private int $minTokens = 200,
        private int $maxTokens = 400,
    ) {}

    /** @return Chunk[] */
    public function chunk(HelpArticle $article): array
    {
        $pieces = [];

        foreach ($this->sections($article->body) as $section) {
            $breadcrumb = implode(self::SEPARATOR, [$article->title, ...$section['path']]);

            foreach ($this->fit($breadcrumb, $section['blocks']) as $blocks) {
                $pieces[] = [
                    'heading' => $breadcrumb,
                    'label' => $section['path'] === [] ? '' : end($section['path']),
                    'body' => implode("\n\n", $blocks),
                ];
            }
        }

        $chunks = [];

        foreach ($this->merge($pieces) as $index => $piece) {
            $content = $piece['heading']."\n".$piece['body'];

            $chunks[] = new Chunk(
                source: $article->source(),
                position: $index + 1,
                heading: $piece['heading'],
                content: $content,
                tokens: $this->counter->count($content),
            );
        }

        return $chunks;
    }

    /** @return array<int, array{path: string[], blocks: string[]}> */
    private function sections(string $markdown): array
    {
        $sections = [];
        $path = [];
        $topLevel = '';
        $blocks = [];
        $block = [];
        $inFence = false;

        $flushBlock = function () use (&$block, &$blocks) {
            $text = trim(implode("\n", $block));

            if ($text !== '') {
                $blocks[] = $text;
            }

            $block = [];
        };

        $flushSection = function () use (&$sections, &$path, &$blocks, &$flushBlock) {
            $flushBlock();

            if ($blocks !== []) {
                $sections[] = ['path' => $path, 'blocks' => $blocks];
            }

            $blocks = [];
        };

        foreach (preg_split('/\R/', $markdown) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
            }

            if (! $inFence && preg_match('/^(#{2,3})\s+(.+?)\s*#*\s*$/', $line, $match)) {
                $flushSection();

                $heading = $match[2];

                if (strlen($match[1]) === 2) {
                    $topLevel = $heading;
                }

                $path = strlen($match[1]) === 2 || $topLevel === ''
                    ? [$heading]
                    : [$topLevel, $heading];

                continue;
            }

            if (! $inFence && trim($line) === '') {
                $flushBlock();

                continue;
            }

            $block[] = $line;
        }

        $flushSection();

        return $sections;
    }

    /** @return array<int, string[]> groups of blocks that each fit in $maxTokens with the breadcrumb on top */
    private function fit(string $breadcrumb, array $blocks): array
    {
        $groups = [];
        $current = [];
        $budget = $this->maxTokens - $this->counter->count($breadcrumb."\n");

        foreach ($this->splitOversized($blocks, $budget) as $block) {
            $candidate = [...$current, $block];

            if ($current !== [] && $this->counter->count(implode("\n\n", $candidate)) > $budget) {
                $groups[] = $current;

                $current = $this->overlapFrom($current, $block, $budget);
            }

            $current[] = $block;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function overlapFrom(array $previous, string $next, int $budget): array
    {
        $last = end($previous);

        $fits = $this->counter->count($last."\n\n".$next) <= $budget
            && $this->counter->count($last) <= intdiv($budget, 4);

        return $fits
            ? [$last]
            : [];
    }

    /** @return string[] */
    private function splitOversized(array $blocks, int $budget): array
    {
        $result = [];

        foreach ($blocks as $block) {
            if ($this->counter->count($block) <= $budget) {
                $result[] = $block;

                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/', $block) ?: [$block];
            $run = '';

            foreach ($sentences as $sentence) {
                $candidate = trim($run.' '.$sentence);

                if ($run !== '' && $this->counter->count($candidate) > $budget) {
                    $result[] = $run;
                    $run = $sentence;

                    continue;
                }

                $run = $candidate;
            }

            if ($run !== '') {
                $result[] = $run;
            }
        }

        return $result;
    }

    /** @return array<int, array{heading: string, label: string, body: string}> */
    private function merge(array $pieces): array
    {
        $merged = [];
        $buffer = null;

        foreach ($pieces as $piece) {
            if ($buffer === null) {
                $buffer = $piece;

                continue;
            }

            if ($this->tokensOf($buffer) < $this->minTokens && $this->tokensOf($this->join($buffer, $piece)) <= $this->maxTokens) {
                $buffer = $this->join($buffer, $piece);

                continue;
            }

            $merged[] = $buffer;
            $buffer = $piece;
        }

        if ($buffer !== null) {
            $merged[] = $buffer;
        }

        return $merged;
    }

    private function join(array $first, array $second): array
    {
        $label = $second['label'] !== ''
            ? "{$second['label']}\n"
            : '';

        return [
            'heading' => $first['heading'],
            'label' => $first['label'],
            'body' => $first['body']."\n\n".$label.$second['body'],
        ];
    }

    private function tokensOf(array $piece): int
    {
        return $this->counter->count($piece['heading']."\n".$piece['body']);
    }
}
