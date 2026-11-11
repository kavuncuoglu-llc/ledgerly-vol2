<?php

namespace App\Ai\Hardware;

// What the sizing sum needs to know about a model when the server cannot say: bytes per weight by quantization (Chapter 21, Topic 7; Chapter 23's table) and the attention shape of the Llama family.
final class ModelCatalog
{
    public const DEFAULT_QUANTIZATION = 'Q4_K_M';

    private const BYTES_PER_PARAMETER = [
        'F16' => 2.0,
        'BF16' => 2.0,
        'Q8_0' => 1.06,
        'Q6_K' => 0.83,
        'Q5_K_M' => 0.71,
        'Q4_K_M' => 0.6,
        'IQ4_XS' => 0.55,
        'Q4_0' => 0.56,
        'Q3_K_M' => 0.49,
        'Q2_K' => 0.37,
    ];

    // parameters in billions => [layers, KV heads, head dimension]
    private const LLAMA_SHAPES = [
        1 => [16, 8, 64],
        3 => [28, 8, 128],
        8 => [32, 8, 128],
        70 => [80, 8, 128],
        405 => [126, 8, 128],
    ];

    public static function bytesPerParameter(string $quantization): float
    {
        return self::BYTES_PER_PARAMETER[self::normalise($quantization)] ?? self::BYTES_PER_PARAMETER[self::DEFAULT_QUANTIZATION];
    }

    public static function knowsQuantization(string $quantization): bool
    {
        return isset(self::BYTES_PER_PARAMETER[self::normalise($quantization)]);
    }

    public static function normalise(string $quantization): string
    {
        $upper = strtoupper(trim($quantization));

        return match ($upper) {
            'FP16', 'F16' => 'F16',
            default => $upper,
        };
    }

    /** "llama3.1:70b-instruct-q4_K_M" => 70.0; "ledgerly-support-70b" => 70.0; a name without a size => 0.0 */
    public static function parametersFromName(string $name): float
    {
        if (preg_match('/(?<![a-z0-9.])(\d+(?:\.\d+)?)b(?![a-z0-9])/i', $name, $match) !== 1) {
            return 0.0;
        }

        return (float) $match[1];
    }

    public static function quantizationFromName(string $name): string
    {
        if (preg_match('/(?<![a-z0-9])(iq\d_[a-z0-9]+|q\d_[a-z0-9_]*[a-z0-9]|fp16|f16|bf16)(?![a-z0-9])/i', $name, $match) !== 1) {
            return self::DEFAULT_QUANTIZATION;
        }

        return self::normalise($match[1]);
    }

    /** @return array{0: int, 1: int, 2: int}|null layers, KV heads, head dimension, for the sizes the Llama family ships */
    public static function llamaShape(float $billions): ?array
    {
        foreach (self::LLAMA_SHAPES as $size => $shape) {
            if (abs($billions - $size) <= max(0.6, $size * 0.02)) {
                return $shape;
            }
        }

        return null;
    }
}
