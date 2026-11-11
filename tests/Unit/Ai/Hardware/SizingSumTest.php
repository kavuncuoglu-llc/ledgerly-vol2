<?php

use App\Ai\Hardware\ModelCatalog;
use App\Ai\Hardware\ModelShape;
use App\Ai\Hardware\ReachableMemory;
use App\Ai\Hardware\SizingSum;
use Tests\Support\FakeHost;

function llama70b(?float $weightsGib = null): ModelShape
{
    return new ModelShape('llama3.1:70b-instruct-q4_K_M', 70.0, 'Q4_K_M', 80, 8, 128, $weightsGib === null ? null : $weightsGib * ModelShape::BYTES_PER_GIB, 'test');
}

function llama8b(): ModelShape
{
    return new ModelShape('llama3.1:8b', 8.0, 'Q4_K_M', 32, 8, 128, null, 'test');
}

it('costs 128 KiB of cache a token for the 8B and 320 KiB for the 70B, and half at q8_0', function () {
    expect(llama8b()->kvBytesPerToken())->toBe(131072.0)
        ->and(llama70b()->kvBytesPerToken())->toBe(327680.0)
        ->and(llama70b()->kvBytesPerToken('q8_0'))->toBe(163840.0)
        ->and(llama70b()->kvBytesPerToken('q4_0'))->toBe(81920.0);
});

it('does the sum of Figure 21-7 for the 70B: weights, two 8k slots of cache, 12 percent headroom', function () {
    $sizing = (new SizingSum)->compute(llama70b(42.5), 8192, 2);

    expect(round($sizing->weightsGb, 1))->toBe(42.5)
        ->and(round($sizing->kvGb, 1))->toBe(5.0)
        ->and(round($sizing->kvQ8Gb, 1))->toBe(2.5)
        ->and(round($sizing->headroomGb, 1))->toBe(5.7)
        ->and(round($sizing->totalGb, 1))->toBe(53.2);
});

it('does the 8B column too, and the q8_0 cache saves exactly half of the cache term', function () {
    $fp16 = (new SizingSum)->compute(llama8b(), 8192, 2);
    $q8 = (new SizingSum)->compute(llama8b(), 8192, 2, 'q8_0');

    expect(round($fp16->kvGb, 1))->toBe(2.0)
        ->and(round($q8->kvGb, 1))->toBe(1.0)
        ->and($q8->totalGb)->toBeLessThan($fp16->totalGb);
});

it('estimates weights from parameters and bytes per parameter when no file size is known', function () {
    expect(round(llama70b()->weightsGb(), 1))->toBe(39.1)
        ->and(ModelCatalog::bytesPerParameter('Q8_0'))->toBe(1.06)
        ->and(ModelCatalog::bytesPerParameter('q4_k_m'))->toBe(0.6)
        ->and(ModelCatalog::bytesPerParameter('fp16'))->toBe(2.0);
});

it('grows the cache with context and with slots and nothing else', function () {
    $base = (new SizingSum)->compute(llama70b(42.5), 4096, 1);
    $more = (new SizingSum)->compute(llama70b(42.5), 16384, 4);

    expect($more->weightsGb)->toBe($base->weightsGb)
        ->and(round($more->kvGb / $base->kvGb))->toBe(16.0);
});

it('lists what to try, cheapest first, and the total each one reaches', function () {
    $shape = llama70b(42.5);
    $sum = new SizingSum;
    $sizing = $sum->compute($shape, 8192, 2);

    $remedies = $sum->remedies($shape, $sizing, 40.0);

    expect($remedies[0])->toStartWith('quantize the KV cache to q8_0')
        ->and($remedies)->toContain('one slot instead of 2: '.sprintf('%.1f', $sum->compute($shape, 8192, 1)->totalGb).' GB total')
        ->and(end($remedies))->toContain('short by 13.2 GB');
});

it('reads the model size and quantization out of a tag', function () {
    expect(ModelCatalog::parametersFromName('llama3.1:70b-instruct-q4_K_M'))->toBe(70.0)
        ->and(ModelCatalog::parametersFromName('ledgerly-support-70b'))->toBe(70.0)
        ->and(ModelCatalog::parametersFromName('llama3.2:3b'))->toBe(3.0)
        ->and(ModelCatalog::parametersFromName('nomic-embed-text'))->toBe(0.0)
        ->and(ModelCatalog::quantizationFromName('llama3.1:8b-instruct-q8_0'))->toBe('Q8_0')
        ->and(ModelCatalog::quantizationFromName('llama3.1:8b-instruct-q4_K_M'))->toBe('Q4_K_M')
        ->and(ModelCatalog::quantizationFromName('llama3.1:8b'))->toBe('Q4_K_M')
        ->and(ModelCatalog::llamaShape(70.6))->toBe([80, 8, 128])
        ->and(ModelCatalog::llamaShape(14.0))->toBeNull();
});

it('gives a Mac 75 percent of its RAM, or what the sysctl says', function () {
    expect((new ReachableMemory(FakeHost::mac(128)))->detect())->toBe([96.0, 'macOS; iogpu.wired_limit_mb unset → ~75% of 128 GB'])
        ->and((new ReachableMemory(FakeHost::mac(128, 110000)))->detect()[0])->toBe(110.0);
});

it('adds the carve-out and the GTT on a Linux APU', function () {
    $gib = ModelShape::BYTES_PER_GIB;

    $host = new FakeHost(
        'Linux',
        files: [
            '/sys/class/drm/card0/device/mem_info_vram_total' => (string) (64 * $gib),
            '/sys/class/drm/card0/device/mem_info_gtt_total' => (string) (48 * $gib),
        ],
        globs: [
            '/sys/class/drm/card*/device/mem_info_vram_total' => ['/sys/class/drm/card0/device/mem_info_vram_total'],
            '/sys/class/drm/card*/device/mem_info_gtt_total' => ['/sys/class/drm/card0/device/mem_info_gtt_total'],
        ],
    );

    expect((new ReachableMemory($host))->detect())->toBe([112.0, 'Linux APU; firmware carve-out 64 GB + GTT 48 GB']);
});

it('sums VRAM over the GPUs nvidia-smi lists', function () {
    $host = new FakeHost('Linux', ['nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits' => "24564\n24564"]);

    [$gib, $label] = (new ReachableMemory($host))->detect();

    expect(round($gib, 1))->toBe(48.0)
        ->and($label)->toBe('NVIDIA VRAM on 2 GPUs');
});

it('falls back to system RAM on a CPU-only Linux box, and gives up on an OS it does not know', function () {
    $cpu = new FakeHost('Linux', files: ['/proc/meminfo' => "MemTotal:       16777216 kB\nMemFree: 1 kB"]);

    expect((new ReachableMemory($cpu))->detect())->toBe([16.0, 'no GPU found; system RAM'])
        ->and((new ReachableMemory(new FakeHost('Windows')))->detect())->toBeNull();
});
