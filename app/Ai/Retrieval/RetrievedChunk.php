<?php

namespace App\Ai\Retrieval;

use App\Ai\Embeddings\MarkdownChunker;
use Illuminate\Support\Str;

final readonly class RetrievedChunk
{
    public function __construct(
        public int $id,
        public string $source,
        public string $title,
        public string $headingPath,
        public string $heading,
        public string $content,
        public string $helpVersion,
        public float $similarity,
        public ?float $keywordRank,
        public float $score,
        public string $chunkKey = '',
        public ?int $tenantId = null,
        public ?array $plans = null,
        public ?string $matched = null,
    ) {}

    public function isShared(): bool
    {
        return $this->tenantId === null
            && $this->plans === null;
    }

    public function article(): string
    {
        return Str::of($this->source)
            ->after('help/')
            ->beforeLast('.md')
            ->toString();
    }

    public function slug(): string
    {
        return Str::afterLast($this->article(), '/');
    }

    public function section(): string
    {
        $parts = explode(MarkdownChunker::SEPARATOR, $this->heading);

        return end($parts);
    }

    public function label(): string
    {
        return $this->slug().MarkdownChunker::SEPARATOR.$this->section();
    }

    public function url(): string
    {
        return '/help/'.$this->article().'#'.Str::slug($this->section());
    }

    public function body(): string
    {
        $text = $this->content;

        $firstLine = Str::before($text, "\n");

        return $firstLine === $this->heading
            ? ltrim(Str::after($text, "\n"))
            : $text;
    }

    public function matchText(): string
    {
        return $this->matched ?? $this->heading."\n".$this->body();       // score the child that matched, ground on the parent
    }
}
