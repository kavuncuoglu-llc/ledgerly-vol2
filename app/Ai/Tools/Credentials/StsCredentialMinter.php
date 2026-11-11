<?php

namespace App\Ai\Tools\Credentials;

use Carbon\CarbonImmutable;
use RuntimeException;

// Fifteen-minute credentials for one tenant's storage prefix. The STS client is passed in (aws/aws-sdk-php is not a dependency of this repository; Volume 3's document tools bring it).
final class StsCredentialMinter implements ScopedCredentialMinter
{
    public const SECONDS = 900;

    public function __construct(
        private object $sts,
        private string $roleArn,
        private string $bucket,
    ) {}

    public function mint(int $tenantId, string $tool, bool $readOnly = true): TemporaryCredentials
    {
        if (! method_exists($this->sts, 'assumeRole')) {
            throw new RuntimeException('The STS client must offer assumeRole(). Install aws/aws-sdk-php.');
        }

        $result = $this->sts->assumeRole([
            'RoleArn' => $this->roleArn,
            'RoleSessionName' => "ledgerly-{$tool}-t{$tenantId}",
            'DurationSeconds' => self::SECONDS,
            'Policy' => json_encode(SessionPolicy::forTenantPrefix($this->bucket, $tenantId, $readOnly)),
        ]);

        $credentials = $result['Credentials'];

        return new TemporaryCredentials(
            accessKeyId: $credentials['AccessKeyId'],
            secretAccessKey: $credentials['SecretAccessKey'],
            sessionToken: $credentials['SessionToken'],
            expiresAt: CarbonImmutable::now()->addSeconds(self::SECONDS),
            scope: "tenants/{$tenantId}/ ".($readOnly ? 'read' : 'read-write'),
        );
    }
}
