<?php

namespace App\Ai\Retrieval;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\RewritePrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueryRewriter
{
    public function __construct(private AiManager $ai, private string $mode = 'rewrite') {}

    public function withMode(string $mode): self
    {
        return new self($this->http, $mode);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return string[] phrasings to retrieve on besides the original; empty when rewriting is off, skipped or failed */
    public function variants(string $question): array
    {
        if (! in_array($this->mode, RewritePrompt::MODES, true)) {
            return [];
        }

        if (IdentifierPattern::matches($question)) {
            return [];                                                               // an invoice number should reach the keyword side exactly as typed
        }

        return Cache::remember(
            'ledgerly.rewrite.'.md5($this->mode.'|'.RewritePrompt::VERSION.'|'.app(AiManager::class)->model('local').'|'.$question),
            3600,
            fn () => $this->ask($question),
        );
    }

    private function ask(string $question): array
    {
        try {
            $response = $this->ai->for('retrieval.rewrite')->chat(ChatRequest::make(
                RewritePrompt::messages($this->mode, $question),
                ['profile' => 'deterministic', 'max_tokens' => RewritePrompt::maxTokens($this->mode), 'prompt_version' => RewritePrompt::VERSION],
                'retrieval.rewrite',
            ));
        } catch (Throwable $e) {
            Log::warning('Query rewrite skipped: the model did not answer', ['error' => $e->getMessage()]);   // retrieval goes on with the original question

            return [];
        }

        return $this->lines((string) $response->content, $question);
    }

    private function lines(string $text, string $question): array
    {
        $lines = array_map(
            fn (string $line) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line), " \t\"“”"),
            preg_split('/\R/', trim($text)) ?: [],
        );

        $lines = array_filter($lines, fn (string $line) => $line !== '' && mb_strtolower($line) !== mb_strtolower($question));

        if ($this->mode === 'hyde') {
            return $lines === []
                ? []
                : [implode(' ', $lines)];
        }

        return array_slice(array_values(array_unique($lines)), 0, $this->mode === 'expand' ? 3 : 1);
    }
}
