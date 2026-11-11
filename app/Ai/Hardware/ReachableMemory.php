<?php

namespace App\Ai\Hardware;

// How much memory a model may use on this machine (Chapter 21, Topics 2 to 4): VRAM on a discrete card, about 75 percent of RAM on a Mac unless the sysctl raises it, firmware carve-out plus GTT on a Linux APU, plain RAM on a CPU box.
final class ReachableMemory
{
    public const MAC_DEFAULT_SHARE = 0.75;

    public function __construct(private HostProbe $host) {}

    /** @return array{0: float, 1: string}|null GiB and a sentence saying where the number came from; null when this machine cannot be read */
    public function detect(): ?array
    {
        return match ($this->host->os()) {
            'Darwin' => $this->mac(),
            'Linux' => $this->linux(),
            default => null,
        };
    }

    private function mac(): ?array
    {
        $bytes = (float) $this->host->run('sysctl -n hw.memsize');

        if ($bytes <= 0) {
            return null;
        }

        $totalGb = $bytes / ModelShape::BYTES_PER_GIB;
        $limitMb = (int) $this->host->run('sysctl -n iogpu.wired_limit_mb');

        if ($limitMb > 0) {
            return [min($limitMb / 1000.0, $totalGb), sprintf('macOS; iogpu.wired_limit_mb=%d of %.0f GB', $limitMb, $totalGb)];
        }

        return [$totalGb * self::MAC_DEFAULT_SHARE, sprintf('macOS; iogpu.wired_limit_mb unset → ~75%% of %.0f GB', $totalGb)];
    }

    private function linux(): ?array
    {
        return $this->nvidia() ?? $this->amdApu() ?? $this->cpuOnly();
    }

    private function nvidia(): ?array
    {
        $output = $this->host->run('nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits');

        $megabytes = array_filter(array_map('floatval', preg_split('/\R/', $output) ?: []));

        if ($megabytes === []) {
            return null;
        }

        return [array_sum($megabytes) / 1024, sprintf('NVIDIA VRAM on %d GPU%s', count($megabytes), count($megabytes) === 1 ? '' : 's')];
    }

    private function amdApu(): ?array
    {
        $carveOut = $this->sumFiles('/sys/class/drm/card*/device/mem_info_vram_total');
        $gtt = $this->sumFiles('/sys/class/drm/card*/device/mem_info_gtt_total');

        if ($carveOut <= 0) {
            return null;
        }

        return [
            ($carveOut + $gtt) / ModelShape::BYTES_PER_GIB,
            sprintf('Linux APU; firmware carve-out %.0f GB + GTT %.0f GB', $carveOut / ModelShape::BYTES_PER_GIB, $gtt / ModelShape::BYTES_PER_GIB),
        ];
    }

    private function cpuOnly(): ?array
    {
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $this->host->read('/proc/meminfo'), $match) !== 1) {
            return null;
        }

        return [((float) $match[1]) * 1024 / ModelShape::BYTES_PER_GIB, 'no GPU found; system RAM'];
    }

    private function sumFiles(string $pattern): float
    {
        $total = 0.0;

        foreach ($this->host->glob($pattern) as $path) {
            $total += (float) $this->host->read($path);
        }

        return $total;
    }
}
