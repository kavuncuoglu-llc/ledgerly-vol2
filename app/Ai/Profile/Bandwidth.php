<?php

namespace App\Ai\Profile;

use App\Ai\Hardware\HostProbe;

// The memory bandwidth decode speed is bound by (Chapter 3), for the machines Chapter 21 names, from the chip tiers in Figure 21-3 and the Strix Halo figure in Topic 4. A tier, not a spec sheet: pass --bandwidth for the real number.
final class Bandwidth
{
    private const APPLE_TIERS = [
        'Ultra' => 800.0,
        'Max' => 475.0,
        'Pro' => 200.0,
        '' => 110.0,
    ];

    public const STRIX_HALO = 250.0;

    public function __construct(private HostProbe $host) {}

    /** @return array{0: string, 1: float}|null a label and GB/s */
    public function detect(): ?array
    {
        return match ($this->host->os()) {
            'Darwin' => $this->apple(),
            'Linux' => $this->strixHalo(),
            default => null,
        };
    }

    private function apple(): ?array
    {
        $brand = $this->host->run('sysctl -n machdep.cpu.brand_string');

        if (preg_match('/Apple (M\d+)(?: (Pro|Max|Ultra))?/', $brand, $match) !== 1) {
            return null;
        }

        $tier = $match[2] ?? '';

        return [trim($match[1].' '.$tier), self::APPLE_TIERS[$tier]];
    }

    private function strixHalo(): ?array
    {
        if (! str_contains($this->host->read('/proc/cpuinfo'), 'Ryzen AI Max')) {
            return null;
        }

        return ['Strix Halo', self::STRIX_HALO];
    }
}
