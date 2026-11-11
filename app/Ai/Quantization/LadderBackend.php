<?php

namespace App\Ai\Quantization;

// Chapter 44, Topic 3: what eval:quant-ladder needs from the machine it runs on. The default is a pinned llama.cpp and a native Ollama; a test supplies a fake.
interface LadderBackend
{
    /**
     * @param  list<string>  $levels  f16, q8_0, q5_k_m, q4_k_m+imatrix, q4_k_m, q3_k_m
     * @return array<string, array{path: string, bytes: int}> level => the GGUF made for it (f16 is the source itself)
     */
    public function quantize(string $f16, array $levels, ?string $calibration, string $directory): array;

    public function load(string $name, string $gguf): void;

    public function unload(string $name): void;

    /**
     * @param  list<array>  $messages
     * @return array{content: string, ttft_seconds: float, decode_tps: float}
     */
    public function chat(string $name, array $messages): array;
}
