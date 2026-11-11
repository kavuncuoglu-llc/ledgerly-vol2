<?php

namespace App\Ai\Profile;

use App\Ai\Hardware\ModelShape;

// Reads what the sampling loop in ShellMemorySampler printed: one block per tick, "@@sample <epoch>", "@@ps" and the matching processes, "@@gpu" and whatever the platform's tool said.
final class SampleParser
{
    /**
     * @param  list<string>  $processNames
     * @return list<MemorySample>
     */
    public static function parse(string $output, string $gpuKind, array $processNames): array
    {
        $samples = [];

        foreach (preg_split('/^@@sample /m', $output) ?: [] as $block) {
            if (trim($block) === '') {
                continue;
            }

            $sample = self::block($block, $gpuKind, $processNames);

            if ($sample !== null) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    private static function block(string $block, string $gpuKind, array $processNames): ?MemorySample
    {
        if (preg_match('/^(\d+)\s*$/m', $block, $epoch) !== 1) {
            return null;
        }

        [$ps, $gpu] = array_pad(explode('@@gpu', explode('@@ps', $block, 2)[1] ?? '', 2), 2, '');

        return new MemorySample((float) $epoch[1], self::resident($ps, $processNames), self::gpu($gpu, $gpuKind));
    }

    private static function resident(string $ps, array $processNames): ?float
    {
        $kibibytes = 0.0;
        $matched = false;

        foreach (preg_split('/\R/', trim($ps)) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $match) !== 1) {
                continue;
            }

            if (! self::isModelServer(basename(trim($match[2])), $processNames)) {
                continue;
            }

            $matched = true;
            $kibibytes += (float) $match[1];
        }

        if (! $matched) {
            return null;
        }

        return $kibibytes * 1024 / ModelShape::BYTES_PER_GIB;
    }

    private static function isModelServer(string $command, array $processNames): bool
    {
        foreach ($processNames as $name) {
            if (str_contains($command, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function gpu(string $text, string $kind): ?float
    {
        return match ($kind) {
            'ioreg' => self::ioreg($text),
            'nvidia' => self::sum($text, 1024),
            'sysfs' => self::sum($text, ModelShape::BYTES_PER_GIB),
            default => null,
        };
    }

    // "In use system memory"=45613907968 is what Metal has mapped on Apple Silicon; the largest accelerator wins
    private static function ioreg(string $text): ?float
    {
        if (preg_match_all('/"In use system memory"\s*=\s*(\d+)/', $text, $matches) < 1) {
            return null;
        }

        return max(array_map('floatval', $matches[1])) / ModelShape::BYTES_PER_GIB;
    }

    // nvidia-smi prints MiB per GPU, sysfs prints bytes: $perGib is how many of that unit make a GiB
    private static function sum(string $text, float $perGib): ?float
    {
        $values = array_filter(array_map('floatval', preg_split('/\s+/', trim($text)) ?: []));

        if ($values === []) {
            return null;
        }

        return array_sum($values) / $perGib;
    }
}
