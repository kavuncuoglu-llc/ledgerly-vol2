<?php

namespace App\Ai\Tools\Credentials;

interface ScopedCredentialMinter
{
    public function mint(int $tenantId, string $tool, bool $readOnly = true): TemporaryCredentials;
}
