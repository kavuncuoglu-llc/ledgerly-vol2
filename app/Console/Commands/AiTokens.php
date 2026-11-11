<?php

namespace App\Console\Commands;

use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use Illuminate\Console\Command;
use Throwable;

class AiTokens extends Command
{
    protected $signature = 'ai:tokens
        {text? : The text to count}
        {--file= : Count the contents of a file instead}
        {--ratio=3.6 : Characters per token for the estimate}
        {--encoding=o200k_base : tiktoken encoding for the exact count}
        {--no-exact : Skip the tiktoken count (it downloads the vocabulary on first use)}';

    protected $description = 'Count the tokens in a piece of text: a ratio estimate and an exact tiktoken count';

    public function handle(): int
    {
        $text = $this->resolveText();

        if ($text === '') {
            $this->error('Give some text, or --file=path.');

            return self::FAILURE;
        }

        $characters = mb_strlen($text);
        $rows = [$this->ratioRow($text, $characters)];

        if (! $this->option('no-exact')) {
            $rows[] = $this->exactRow($text, $characters);
        }

        $this->line("Characters: {$characters}");
        $this->table(['Method', 'Tokens', 'Chars per token'], $rows);

        return self::SUCCESS;
    }

    private function resolveText(): string
    {
        if ($this->option('file')) {
            return (string) @file_get_contents($this->option('file'));
        }

        return (string) $this->argument('text');
    }

    private function ratioRow(string $text, int $characters): array
    {
        $tokens = (new RatioCounter((float) $this->option('ratio')))->count($text);

        return ["ratio ({$this->option('ratio')} chars/token)", $tokens, $this->charsPerToken($characters, $tokens)];
    }

    private function exactRow(string $text, int $characters): array
    {
        $label = "tiktoken {$this->option('encoding')}";

        try {
            $tokens = (new TiktokenCounter($this->option('encoding')))->count($text);
        } catch (Throwable $e) {
            return [$label, 'unavailable', $e->getMessage()];
        }

        return [$label, $tokens, $this->charsPerToken($characters, $tokens)];
    }

    private function charsPerToken(int $characters, int $tokens): string
    {
        if ($tokens === 0) {
            return '-';
        }

        return number_format($characters / $tokens, 2);
    }
}
