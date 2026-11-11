<?php

namespace App\Ai\Ingestion;

use App\Ai\Embeddings\Chunk;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Str;

// Splits a heading chunk (a parent, 200 to 400 tokens) into children of about $maxTokens that are about one thing each.
final class ChildSplitter
{
    public function __construct(private TokenCounter $counter, private int $maxTokens = 160) {}

    /** @return string[] child texts without the breadcrumb line; fewer than two means the parent is already small enough to match on itself */
    public function split(Chunk $parent): array
    {
        $body = Str::after($parent->content, "\n");

        $blocks = [];

        foreach (preg_split('/\n{2,}/', trim($body)) ?: [] as $block) {
            foreach ($this->fit(trim($block)) as $piece) {
                $blocks[] = $piece;
            }
        }

        $children = [];
        $current = '';

        foreach ($blocks as $block) {
            $candidate = $current === ''
                ? $block
                : $current."\n\n".$block;

            if ($current !== '' && $this->counter->count($candidate) > $this->maxTokens) {
                $children[] = $current;
                $current = $block;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $children[] = $current;
        }

        return $children;
    }

    /** @return string[] */
    private function fit(string $block): array
    {
        if ($block === '' || $this->counter->count($block) <= $this->maxTokens) {
            return $block === ''
                ? []
                : [$block];
        }

        $pieces = [];
        $run = '';

        foreach (preg_split('/(?<=[.!?])\s+/', $block) ?: [$block] as $sentence) {
            $candidate = trim($run.' '.$sentence);

            if ($run !== '' && $this->counter->count($candidate) > $this->maxTokens) {
                $pieces[] = $run;
                $run = $sentence;

                continue;
            }

            $run = $candidate;
        }

        if ($run !== '') {
            $pieces[] = $run;
        }

        return $pieces;
    }
}
