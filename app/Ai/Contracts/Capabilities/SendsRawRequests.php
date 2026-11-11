<?php

namespace App\Ai\Contracts\Capabilities;

interface SendsRawRequests
{
    public function raw(string $method, string $path, array $body = []): array;
}
