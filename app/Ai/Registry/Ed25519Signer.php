<?php

namespace App\Ai\Registry;

use RuntimeException;

// The default signer: Ed25519 through libsodium, which PHP ships. The private key is a file only the pipeline can read (REGISTRY_SIGNING_KEY_FILE); the public keys the servers trust are config('ai.registry.signing.keys'), by key id.
// What is signed is the artifact's digest with a fixed prefix, so a signature made for a model file is not a signature for anything else.
final class Ed25519Signer implements Signer
{
    public const PREFIX = "ledgerly/model-artifact:v1\n";

    public function name(): string
    {
        return 'ed25519';
    }

    public function sign(string $path, string $sha256): array
    {
        $file = (string) config('ai.registry.signing.private_key_file');

        if ($file === '' || ! is_file($file)) {
            throw new RuntimeException('There is no signing key: set REGISTRY_SIGNING_KEY_FILE to a key made by registry:keygen. Only the pipeline holds it.');
        }

        $secret = base64_decode(trim((string) file_get_contents($file)), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException("{$file} does not hold an Ed25519 secret key made by registry:keygen.");
        }

        return [
            'signature' => base64_encode(sodium_crypto_sign_detached(self::PREFIX.$sha256, $secret)),
            'key_id' => (string) config('ai.registry.signing.pipeline'),
        ];
    }

    public function verify(string $path, string $sha256, string $signature, string $keyId): bool
    {
        $public = base64_decode((string) (config('ai.registry.signing.keys')[$keyId] ?? ''), true);

        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $raw = base64_decode($signature, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($raw, self::PREFIX.$sha256, $public);
    }

    /** @return array{public: string, secret: string} base64 */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
