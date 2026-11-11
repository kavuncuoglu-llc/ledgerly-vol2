<?php

namespace App\Ai\Profile;

interface MemorySampler
{
    public function start(): void;

    /** @return list<MemorySample> */
    public function stop(): array;
}
