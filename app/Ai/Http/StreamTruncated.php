<?php

namespace App\Ai\Http;

use RuntimeException;

final class StreamTruncated extends RuntimeException
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $finishReason = null,
        public readonly ?array $usage = null,
        public readonly ?string $model = null,
    ) {
        parent::__construct('The stream ended before [DONE]; the reply is incomplete.');
    }
}
