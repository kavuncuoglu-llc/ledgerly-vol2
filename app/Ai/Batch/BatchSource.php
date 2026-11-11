<?php

namespace App\Ai\Batch;

interface BatchSource
{
    /** @return iterable<int, array{custom_id: string, messages: array, max_tokens?: int}> every request has our own id, so results can come back in any order */
    public function requests(string $spec): iterable;
}
