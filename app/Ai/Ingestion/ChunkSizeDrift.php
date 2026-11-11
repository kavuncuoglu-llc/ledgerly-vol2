<?php

namespace App\Ai\Ingestion;

use RuntimeException;

final class ChunkSizeDrift extends RuntimeException
{
    public static function because(array $reasons): self
    {
        return new self('Chunk sizes moved: '.implode('; ', $reasons).'. Nothing was written. If the new sizes are intended, run help:ingest with --accept-chunk-sizes.');
    }
}
