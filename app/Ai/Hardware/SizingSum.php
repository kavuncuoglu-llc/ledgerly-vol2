<?php

namespace App\Ai\Hardware;

final class SizingSum
{
    public const HEADROOM = 0.12;                 // the book budgets 10 to 15 percent of the reachable memory; 12 percent of what the model itself takes is the sum in Figure 21-7

    public function compute(ModelShape $shape, int $context, int $slots, string $cache = 'fp16'): Sizing
    {
        $weights = $shape->weightsGb();
        $kv = $this->cacheGb($shape, $context, $slots, $cache);
        $kvQ8 = $this->cacheGb($shape, $context, $slots, 'q8_0');

        $headroom = ($weights + $kv) * self::HEADROOM;

        return new Sizing(
            weightsGb: $weights,
            kvGb: $kv,
            kvQ8Gb: $kvQ8,
            headroomGb: $headroom,
            totalGb: $weights + $kv + $headroom,
            cache: strtolower($cache),
            context: $context,
            slots: $slots,
        );
    }

    private function cacheGb(ModelShape $shape, int $context, int $slots, string $cache): float
    {
        return $shape->kvBytesPerToken($cache) * $context * $slots / ModelShape::BYTES_PER_GIB;
    }

    /**
     * What to try, cheapest first, when the sum does not fit (the table at the end of Topic 7). Each entry is a sentence with the total it would reach.
     *
     * @return list<string>
     */
    public function remedies(ModelShape $shape, Sizing $sizing, float $reachableGb): array
    {
        $remedies = [];

        if ($sizing->cache === 'fp16') {
            $withQ8 = $this->compute($shape, $sizing->context, $sizing->slots, 'q8_0');

            $remedies[] = sprintf('quantize the KV cache to q8_0 (-ctk q8_0 -ctv q8_0): %.1f GB total', $withQ8->totalGb);
        }

        if ($sizing->slots > 1) {
            $fewer = $this->compute($shape, $sizing->context, 1, $sizing->cache);

            $remedies[] = sprintf('one slot instead of %d: %.1f GB total', $sizing->slots, $fewer->totalGb);
        }

        if ($sizing->context > 4096) {
            $shorter = $this->compute($shape, intdiv($sizing->context, 2), $sizing->slots, $sizing->cache);

            $remedies[] = sprintf('half the context (%d): %.1f GB total', intdiv($sizing->context, 2), $shorter->totalGb);
        }

        $lower = $this->lowerQuantization($shape);

        if ($lower !== null) {
            $remedies[] = sprintf('drop to %s: %.1f GB total', $lower->quantization, $this->compute($lower, $sizing->context, $sizing->slots, $sizing->cache)->totalGb);
        }

        $remedies[] = sprintf('raise the OS cap on GPU memory, or buy more memory (short by %.1f GB)', max(0.0, -$sizing->spareGb($reachableGb)));

        return $remedies;
    }

    private function lowerQuantization(ModelShape $shape): ?ModelShape
    {
        $ladder = ['F16', 'Q8_0', 'Q6_K', 'Q5_K_M', 'Q4_K_M', 'Q3_K_M'];

        $position = array_search(ModelCatalog::normalise($shape->quantization), $ladder, true);

        if ($position === false) {
            return null;
        }

        if (! isset($ladder[$position + 1])) {
            return null;
        }

        return new ModelShape(
            name: $shape->name,
            parametersBillions: $shape->parametersBillions,
            quantization: $ladder[$position + 1],
            layers: $shape->layers,
            kvHeads: $shape->kvHeads,
            headDimension: $shape->headDimension,
            weightsBytes: null,
            source: 'estimate',
        );
    }
}
