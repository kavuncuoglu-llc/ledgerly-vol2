<?php

namespace App\Ai\Tools\Credentials;

// The policy passed to STS with the role: the session gets the intersection of the role's permissions and this, so it can only touch one tenant's prefix.
final class SessionPolicy
{
    public static function forTenantPrefix(string $bucket, int $tenantId, bool $readOnly = true): array
    {
        $actions = $readOnly
            ? ['s3:GetObject']
            : ['s3:GetObject', 's3:PutObject'];

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => $actions,
                    'Resource' => "arn:aws:s3:::{$bucket}/tenants/{$tenantId}/*",
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => ['s3:ListBucket'],
                    'Resource' => "arn:aws:s3:::{$bucket}",
                    'Condition' => ['StringLike' => ['s3:prefix' => ["tenants/{$tenantId}/*"]]],
                ],
            ],
        ];
    }
}
