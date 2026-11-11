<?php

namespace App\Ai\Timings;

// What `ollama run --verbose` prints, as a value: durations in nanoseconds, the way Ollama reports them.
final class ModelTimings
{
    public function __construct(
        public readonly int $totalNs,
        public readonly int $loadNs,
        public readonly int $promptTokens,
        public readonly int $promptEvalNs,
        public readonly int $completionTokens,
        public readonly int $evalNs,
        public readonly bool $measured,
    ) {}

    public static function fromOllama(array $body): self
    {
        return new self(
            totalNs: (int) ($body['total_duration'] ?? 0),
            loadNs: (int) ($body['load_duration'] ?? 0),
            promptTokens: (int) ($body['prompt_eval_count'] ?? 0),
            promptEvalNs: (int) ($body['prompt_eval_duration'] ?? 0),
            completionTokens: (int) ($body['eval_count'] ?? 0),
            evalNs: (int) ($body['eval_duration'] ?? 0),
            measured: isset($body['eval_duration']),
        );
    }

    // The OpenAI-compatible shape has token counts in `usage` and no durations, so only the wall clock is real.
    public static function fromUsage(array $body, int $wallNs): self
    {
        return new self(
            totalNs: $wallNs,
            loadNs: 0,
            promptTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            promptEvalNs: 0,
            completionTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            evalNs: 0,
            measured: false,
        );
    }

    public function prefillRate(): float
    {
        return $this->rate($this->promptTokens, $this->promptEvalNs);
    }

    public function decodeRate(): float
    {
        return $this->rate($this->completionTokens, $this->evalNs);
    }

    public function overallRate(): float
    {
        return $this->rate($this->completionTokens, $this->totalNs);
    }

    public function totalMs(): int
    {
        return (int) round($this->totalNs / 1_000_000);
    }

    public function report(): array
    {
        if (! $this->measured) {
            return $this->estimatedReport();
        }

        return [
            'total duration' => self::duration($this->totalNs),
            'load duration' => self::duration($this->loadNs),
            'prompt eval count' => "{$this->promptTokens} token(s)",
            'prompt eval duration' => self::duration($this->promptEvalNs),
            'prompt eval rate' => self::rateLabel($this->prefillRate()),
            'eval count' => "{$this->completionTokens} token(s)",
            'eval duration' => self::duration($this->evalNs),
            'eval rate' => self::rateLabel($this->decodeRate()),
        ];
    }

    public static function duration(int $nanoseconds): string
    {
        $milliseconds = $nanoseconds / 1_000_000;

        if ($milliseconds < 1000) {
            return rtrim(rtrim(number_format($milliseconds, 1, '.', ''), '0'), '.').'ms';
        }

        $seconds = $milliseconds / 1000;

        return number_format($seconds, $seconds >= 10 ? 1 : 2, '.', '').'s';
    }

    private function estimatedReport(): array
    {
        return [
            'total duration' => self::duration($this->totalNs),
            'prompt eval count' => "{$this->promptTokens} token(s)",
            'prompt eval rate' => 'n/a (no prefill timing on this endpoint)',
            'eval count' => "{$this->completionTokens} token(s)",
            'eval rate' => 'n/a (no decode timing on this endpoint)',
            'overall rate' => self::rateLabel($this->overallRate()).' (completion tokens over the whole call, prefill included)',
        ];
    }

    private function rate(int $tokens, int $nanoseconds): float
    {
        if ($nanoseconds <= 0) {
            return 0.0;
        }

        return $tokens / ($nanoseconds / 1_000_000_000);
    }

    private static function rateLabel(float $rate): string
    {
        if ($rate <= 0) {
            return 'n/a';
        }

        return number_format($rate, 1, '.', '').' tokens/s';
    }
}
