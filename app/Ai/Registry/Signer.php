<?php

namespace App\Ai\Registry;

// Chapter 44, Topic 8: a signature proves who produced a file, not only that it is unchanged. Whatever signs (a key the pipeline holds, Sigstore's cosign) signs the file's SHA-256; a server verifies the signature and the hash.
interface Signer
{
    public function name(): string;

    /** @return array{signature: string, key_id: string} */
    public function sign(string $path, string $sha256): array;

    public function verify(string $path, string $sha256, string $signature, string $keyId): bool;
}
