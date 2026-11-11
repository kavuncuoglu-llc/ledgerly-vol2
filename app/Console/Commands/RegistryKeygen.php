<?php

namespace App\Console\Commands;

use App\Ai\Registry\Ed25519Signer;
use Illuminate\Console\Command;

class RegistryKeygen extends Command
{
    protected $signature = 'registry:keygen
        {directory : Where the key pair is written (not inside the repository)}
        {--pipeline=ledgerly-training : The key id the public key is trusted under}';

    protected $description = 'Make the pipeline\'s Ed25519 signing key pair (Chapter 44, Topic 8)';

    public function handle(): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/');

        if (str_starts_with(realpath($directory) ?: $directory, base_path())) {
            $this->error('Keep the signing key outside the repository: only the pipeline should have it.');

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $pair = Ed25519Signer::keypair();

        $secret = "{$directory}/{$this->option('pipeline')}.key";

        file_put_contents($secret, $pair['secret']."\n");

        chmod($secret, 0600);

        file_put_contents("{$directory}/{$this->option('pipeline')}.pub", $pair['public']."\n");

        $this->line("  secret key: {$secret}  (REGISTRY_SIGNING_KEY_FILE, on the pipeline only)");
        $this->line("  public key: {$pair['public']}  (REGISTRY_SIGNING_PUBLIC_KEY, on every server)");

        return self::SUCCESS;
    }
}
