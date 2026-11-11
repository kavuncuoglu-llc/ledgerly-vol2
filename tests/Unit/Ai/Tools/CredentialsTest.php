<?php

use App\Ai\Tools\Credentials\CredentialNotConfigured;
use App\Ai\Tools\Credentials\SessionPolicy;
use App\Ai\Tools\Credentials\StsCredentialMinter;
use App\Ai\Tools\Credentials\ToolCredentials;
use App\Ai\Tools\RetryPayment\RetryPayment;

// Chapter 33, Topic 5: a key per tool, and credentials minted for a call.
it('gives a tool only the restricted key named for it', function () {
    $credentials = new ToolCredentials(['retry_payment' => ['key' => 'rk_test_not_a_real_key', 'label' => 'payments: retry', 'scopes' => ['charges:retry']]]);

    $credential = $credentials->for(new RetryPayment);

    expect($credential->label)->toBe('payments: retry')
        ->and($credential->scopes)->toBe(['charges:retry'])
        ->and($credential->secret)->toBe('rk_test_not_a_real_key')
        ->and((string) $credential)->toBe('payments: retry');
});

it('fails closed for a tool with no key', function () {
    $credentials = new ToolCredentials(['retry_payment' => ['key' => '']]);

    expect($credentials->has(new RetryPayment))->toBeFalse();

    $credentials->for(new RetryPayment);
})->throws(CredentialNotConfigured::class);

it('narrows a session to one tenant\'s prefix, read only unless asked', function () {
    $policy = SessionPolicy::forTenantPrefix('ledgerly-docs', 12);

    expect($policy['Statement'][0]['Action'])->toBe(['s3:GetObject'])
        ->and($policy['Statement'][0]['Resource'])->toBe('arn:aws:s3:::ledgerly-docs/tenants/12/*')
        ->and($policy['Statement'][1]['Condition']['StringLike']['s3:prefix'])->toBe(['tenants/12/*'])
        ->and(SessionPolicy::forTenantPrefix('ledgerly-docs', 12, readOnly: false)['Statement'][0]['Action'])->toBe(['s3:GetObject', 's3:PutObject']);
});

it('asks STS for fifteen minutes with that policy', function () {
    $sts = new class
    {
        public array $asked = [];

        public function assumeRole(array $request): array
        {
            $this->asked = $request;

            return ['Credentials' => ['AccessKeyId' => 'ASIAEXAMPLE', 'SecretAccessKey' => 'not-a-secret', 'SessionToken' => 'token']];
        }
    };

    $credentials = (new StsCredentialMinter($sts, 'arn:aws:iam::123456789012:role/ledgerly-documents', 'ledgerly-docs'))->mint(12, 'read_document');

    expect($sts->asked['DurationSeconds'])->toBe(900)
        ->and($sts->asked['RoleSessionName'])->toBe('ledgerly-read_document-t12')
        ->and(json_decode($sts->asked['Policy'], true)['Statement'][0]['Resource'])->toBe('arn:aws:s3:::ledgerly-docs/tenants/12/*')
        ->and($credentials->scope)->toBe('tenants/12/ read')
        ->and($credentials->expiresAt->isFuture())->toBeTrue();
});
