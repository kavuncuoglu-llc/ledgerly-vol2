<?php

namespace App\Ai\Registry;

use Illuminate\Support\Facades\Process;
use RuntimeException;

// Sigstore's cosign, for teams that already run it: `cosign sign-blob --key` on the file (its bundle is the signature kept in the registry), `cosign verify-blob --key` on the other side. Keys are files (COSIGN_KEY for signing, one public key per key id in config for verifying);
// the transparency log is not used (--tlog-upload=false, --insecure-ignore-tlog), because a key pair held by the pipeline is the trust root here; a keyless flow needs an identity provider and is left to the pipeline.
final class CosignSigner implements Signer
{
    public function name(): string
    {
        return 'cosign';
    }

    public function sign(string $path, string $sha256): array
    {
        $key = (string) config('ai.registry.signing.cosign.key');

        if ($key === '' || ! is_file($key)) {
            throw new RuntimeException('There is no cosign key: set COSIGN_KEY to the key file made by `cosign generate-key-pair` (and COSIGN_PASSWORD).');
        }

        $bundle = tempnam(sys_get_temp_dir(), 'cosign');

        try {
            $result = Process::env(['COSIGN_PASSWORD' => (string) config('ai.registry.signing.cosign.password')])->timeout(600)->run([config('ai.registry.signing.cosign.binary', 'cosign'), 'sign-blob', '--yes', '--key', $key, '--use-signing-config=false', '--tlog-upload=false', '--bundle', $bundle, $path]);

            if ($result->failed()) {
                throw new RuntimeException('cosign did not sign '.basename($path).': '.trim($result->errorOutput()));
            }

            return ['signature' => trim((string) file_get_contents($bundle)), 'key_id' => (string) config('ai.registry.signing.pipeline')];
        } finally {
            unlink($bundle);
        }
    }

    public function verify(string $path, string $sha256, string $signature, string $keyId): bool
    {
        $public = (string) (config('ai.registry.signing.cosign.public_keys')[$keyId] ?? '');

        if ($public === '' || ! is_file($public)) {
            return false;
        }

        $bundle = tempnam(sys_get_temp_dir(), 'cosign');

        file_put_contents($bundle, $signature);

        try {
            return Process::timeout(600)->run([config('ai.registry.signing.cosign.binary', 'cosign'), 'verify-blob', '--key', $public, '--bundle', $bundle, '--insecure-ignore-tlog=true', $path])->successful();
        } finally {
            unlink($bundle);
        }
    }
}
