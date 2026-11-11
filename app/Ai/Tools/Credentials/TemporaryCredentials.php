<?php

namespace App\Ai\Tools\Credentials;

use Carbon\CarbonImmutable;

final readonly class TemporaryCredentials
{
    public function __construct(
        public string $accessKeyId,
        public string $secretAccessKey,
        public string $sessionToken,
        public CarbonImmutable $expiresAt,
        public string $scope,
    ) {}
}
