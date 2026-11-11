<?php

namespace App\Ai\Load;

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\TokenCounter;
use App\Models\ModelCall;
use Carbon\CarbonInterface;
use RuntimeException;

// Chapter 24, Topic 7: a load test that replays the SIZE of the app's own requests, not their text. The shape file holds two numbers per request (prompt tokens, completion tokens) taken from model_calls;
// the prompts are made up at that size, with the real system message in front so prefix caching sees what it sees in production. No customer text ever goes into a benchmark file.
final class TrafficShape
{
    private const VOCABULARY = [
        'invoice', 'ledger', 'payment', 'reminder', 'balance', 'customer', 'schedule', 'account', 'receipt', 'statement',
        'recurring', 'overdue', 'credit', 'refund', 'currency', 'tax', 'export', 'report', 'template', 'workflow',
        'approval', 'deposit', 'estimate', 'discount', 'subscription', 'contact', 'address', 'reference', 'status', 'archive',
    ];

    public function __construct(private TokenCounter $tokens) {}

    /** @return list<array{prompt_tokens: int, completion_tokens: int}> */
    public function fromCalls(string $feature, int $limit, ?CarbonInterface $since = null): array
    {
        return ModelCall::query()
            ->where('feature', $feature)
            ->where('prompt_tokens', '>', 0)
            ->where('completion_tokens', '>', 0)
            ->where('finish_reason', 'stop')
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['prompt_tokens', 'completion_tokens'])
            ->map(fn (ModelCall $call) => ['prompt_tokens' => (int) $call->prompt_tokens, 'completion_tokens' => (int) $call->completion_tokens])
            ->values()
            ->all();
    }

    /** @return list<array{prompt_tokens: int, completion_tokens: int}> */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No traffic shape at {$path}. Build one from model_calls with --write-shape.");
        }

        $shapes = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);

            if (! is_array($row) || ! isset($row['prompt_tokens'], $row['completion_tokens'])) {
                continue;
            }

            $shapes[] = ['prompt_tokens' => (int) $row['prompt_tokens'], 'completion_tokens' => (int) $row['completion_tokens']];
        }

        if ($shapes === []) {
            throw new RuntimeException("{$path} has no usable lines (each is {\"prompt_tokens\": 612, \"completion_tokens\": 140}).");
        }

        return $shapes;
    }

    public function write(string $path, array $shapes): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, implode("\n", array_map(fn (array $shape) => json_encode($shape), $shapes))."\n");
    }

    // The dataset `vllm bench serve --dataset-name custom` reads: one {"prompt": ...} per line. Each prompt is the support system message, then filler words until the estimated size is reached.
    public function writeDataset(string $path, array $shapes): int
    {
        $lines = [];

        $prefix = SupportPrompt::base();
        $prefixTokens = $this->tokens->count($prefix);

        foreach (array_values($shapes) as $index => $shape) {
            $lines[] = json_encode([
                'prompt' => $this->prompt($prefix, $prefixTokens, $shape['prompt_tokens'], $index),
                'output_tokens' => $shape['completion_tokens'],
            ], JSON_UNESCAPED_UNICODE);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n");

        return count($lines);
    }

    public function medianCompletion(array $shapes): int
    {
        $sorted = array_column($shapes, 'completion_tokens');

        sort($sorted);

        return $sorted[intdiv(count($sorted), 2)] ?? 128;
    }

    private function prompt(string $prefix, int $prefixTokens, int $wanted, int $seed): string
    {
        $words = [];
        $remaining = max(8, $wanted - $prefixTokens);
        $state = ($seed + 1) * 2654435761 % 4294967296;                           // a tiny generator of its own: the benchmark must not reseed PHP's

        while ($remaining > 0) {
            $state = ($state * 1664525 + 1013904223) % 4294967296;

            $words[] = self::VOCABULARY[$state % count(self::VOCABULARY)].(10 + intdiv($state, 7) % 90);

            $remaining -= 3;                                                       // a word and a number are about three tokens
        }

        return $prefix."\n\nQuestion about: ".implode(' ', $words);
    }
}
