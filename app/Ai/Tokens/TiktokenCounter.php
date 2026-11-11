<?php

namespace App\Ai\Tokens;

use Yethee\Tiktoken\Encoder;
use Yethee\Tiktoken\EncoderProvider;

// exact, for OpenAI-family tokenizers
final class TiktokenCounter implements TokenCounter
{
    private ?Encoder $encoder = null;

    public function __construct(
        private string $encoding = 'o200k_base',
        private ?EncoderProvider $provider = null,
    ) {}

    public function count(string $text): int
    {
        return count($this->encoder()->encode($text));
    }

    private function encoder(): Encoder
    {
        // Loading a vocabulary takes a moment, so keep the encoder for the life of the counter.
        return $this->encoder ??= ($this->provider ?? new EncoderProvider)->get($this->encoding);
    }
}
