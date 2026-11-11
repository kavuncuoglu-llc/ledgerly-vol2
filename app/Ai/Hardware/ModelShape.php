<?php

namespace App\Ai\Hardware;

// The numbers the sizing sum reads off a model: how big the weights are and how much cache one token costs.
final readonly class ModelShape
{
    public const BYTES_PER_GIB = 1_073_741_824;

    private const CACHE_BYTES_PER_ELEMENT = [
        'fp16' => 2.0,
        'f16' => 2.0,
        'q8_0' => 1.0,
        'q4_0' => 0.5,
    ];

    public function __construct(
        public string $name,
        public float $parametersBillions,
        public string $quantization,
        public ?int $layers,
        public ?int $kvHeads,
        public ?int $headDimension,
        public ?float $weightsBytes,
        public string $source,
    ) {}

    public function weightsBytes(): float
    {
        if ($this->weightsBytes !== null) {
            return $this->weightsBytes;
        }

        return $this->parametersBillions * 1_000_000_000 * ModelCatalog::bytesPerParameter($this->quantization);
    }

    public function weightsGb(): float
    {
        return $this->weightsBytes() / self::BYTES_PER_GIB;
    }

    public function hasAttentionShape(): bool
    {
        return $this->layers !== null && $this->kvHeads !== null && $this->headDimension !== null;
    }

    // 2 (K and V) x layers x KV heads x head dimension x bytes per element (Chapter 3's formula)
    public function kvBytesPerToken(string $cache = 'fp16'): float
    {
        $bytes = self::CACHE_BYTES_PER_ELEMENT[strtolower($cache)] ?? self::CACHE_BYTES_PER_ELEMENT['fp16'];

        return 2 * ($this->layers ?? 0) * ($this->kvHeads ?? 0) * ($this->headDimension ?? 0) * $bytes;
    }

    public static function cacheTypes(): array
    {
        return array_keys(self::CACHE_BYTES_PER_ELEMENT);
    }
}
