<?php

namespace App\Ai\Conversations;

use RuntimeException;

final class NoTenant extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self("User {$userId} belongs to no tenant, so the assistant cannot keep a conversation for them.");
    }
}
