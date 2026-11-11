<?php

use App\Ai\Registry\BaseModelCheck;
use App\Ai\Registry\CosignSigner;
use App\Ai\Registry\Ed25519Signer;
use App\Ai\Registry\Signing;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\RegistryWorld;

// Chapter 44, Topics 8 and 9: hashes, signatures, the base and the tools, checked by `registry:verify` and by every server before it loads a file.
beforeEach(function () {
    RegistryWorld::boot();

    $this->keys = Ed25519Signer::keypair();

    File::put(RegistryWorld::$directory.'/pipeline.key', $this->keys['secret']."\n");

    config([
        'ai.registry.signing.required' => true,
        'ai.registry.signing.driver' => 'ed25519',
        'ai.registry.signing.private_key_file' => RegistryWorld::$directory.'/pipeline.key',
        'ai.registry.signing.keys' => ['ledgerly-training' => $this->keys['public']],
    ]);

    $this->entry = RegistryWorld::entry();
});

afterEach(function () {
    RegistryWorld::clean();
});

function verifyOutput(string $name = 'billing', int $version = 1): array
{
    $code = Artisan::call('registry:verify', ['name' => $name, 'version' => $version]);

    return [$code, Artisan::output()];
}

it('signs every artifact with the pipeline key and verifies them all', function () {
    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->expectsOutputToContain('signed by ledgerly-training (ed25519)')->assertExitCode(0);

    [$code, $output] = verifyOutput();

    expect($code)->toBe(0)
        ->and($output)->toContain('adapter adapters.safetensors')->toContain('sha256 matches · signature valid (ledgerly-training)')
        ->toContain('gguf billing-v1.Q4_K_M.gguf')->toContain('llama.cpp b11776 (ead9f32) · peft 0.21.2 · recorded in entry')
        ->and(array_keys($this->entry->refresh()->signatures))->toBe(['adapter', 'gguf']);
});

it('fails an unsigned artifact when signatures are required, and passes it when they are not', function () {
    [$code, $output] = verifyOutput();

    expect($code)->toBe(1)->and($output)->toContain('FAILED')->toContain('not signed');

    config(['ai.registry.signing.required' => false]);

    expect(verifyOutput()[0])->toBe(0);
});

it('catches a file swapped after it was made: the hash no longer matches the registry', function () {
    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->assertExitCode(0);

    File::put($this->entry->artifacts['files']['gguf'], 'a different model');

    [$code, $output] = verifyOutput();

    expect($code)->toBe(1)->and($output)->toContain('gguf billing-v1.Q4_K_M.gguf')->toContain('sha256 does not match the registry');
});

it('catches a swapped file whose registry hash was rewritten to match: the signature was made for another file', function () {
    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->assertExitCode(0);

    $entry = $this->entry->refresh();

    File::put($entry->artifacts['files']['gguf'], 'a different model');

    $entry->update(['artifacts' => ['gguf' => 'sha256:'.hash_file('sha256', $entry->artifacts['files']['gguf'])] + $entry->artifacts]);

    [$code, $output] = verifyOutput();

    expect($code)->toBe(1)->and($output)->toContain('the signature was made for a different file');
});

it('catches a signature made with a key the servers do not trust', function () {
    $other = Ed25519Signer::keypair();

    File::put(RegistryWorld::$directory.'/other.key', $other['secret']."\n");

    config(['ai.registry.signing.private_key_file' => RegistryWorld::$directory.'/other.key']);

    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->assertExitCode(0);

    [$code, $output] = verifyOutput();

    expect($code)->toBe(1)->and($output)->toContain('signature NOT valid for key ledgerly-training');
});

it('refuses to sign a file that is not the file the registry recorded, and says there is no key when there is none', function () {
    File::put($this->entry->artifacts['files']['adapter'], 'tampered');

    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->expectsOutputToContain('Something changed it after it was made')->assertExitCode(1);

    File::put($this->entry->artifacts['files']['adapter'], 'adapter weights billing 1');

    config(['ai.registry.signing.private_key_file' => null]);

    $this->artisan('registry:sign', ['name' => 'billing', 'version' => 1])->expectsOutputToContain('There is no signing key')->assertExitCode(1);
});

it('names the tools that made an artifact, and fails an entry that records none', function () {
    config(['ai.registry.signing.required' => false]);

    $this->entry->update(['tools' => null]);

    [$code, $output] = verifyOutput();

    expect($code)->toBe(1)->and($output)->toContain('tools')->toContain('none recorded');
});

it('makes a key pair outside the repository and refuses one inside it', function () {
    $this->artisan('registry:keygen', ['directory' => RegistryWorld::$directory.'/keys'])->expectsOutputToContain('secret key:')->assertExitCode(0);

    expect(decoct(fileperms(RegistryWorld::$directory.'/keys/ledgerly-training.key') & 0777))->toBe('600')
        ->and(trim(File::get(RegistryWorld::$directory.'/keys/ledgerly-training.pub')))->not->toBe('');

    $this->artisan('registry:keygen', ['directory' => base_path('storage/keys')])->expectsOutputToContain('outside the repository')->assertExitCode(1);
});

it('signs and verifies with cosign when that is the driver', function () {
    File::put(RegistryWorld::$directory.'/cosign.key', 'key');
    File::put(RegistryWorld::$directory.'/cosign.pub', 'pub');

    config(['ai.registry.signing.cosign.key' => RegistryWorld::$directory.'/cosign.key', 'ai.registry.signing.cosign.public_keys' => ['ledgerly-training' => RegistryWorld::$directory.'/cosign.pub']]);

    Process::fake(function ($process) {
        if (in_array('sign-blob', $process->command, true)) {
            file_put_contents($process->command[array_search('--bundle', $process->command, true) + 1], '{"signature":"MEUCIQ-signature"}');

            return Process::result('');
        }

        return Process::result('Verified OK');
    });

    $signer = new CosignSigner;

    $signed = $signer->sign('/models/a.gguf', 'sha256:aa');

    expect($signed)->toBe(['signature' => '{"signature":"MEUCIQ-signature"}', 'key_id' => 'ledgerly-training'])
        ->and($signer->verify('/models/a.gguf', 'sha256:aa', $signed['signature'], 'ledgerly-training'))->toBeTrue()
        ->and($signer->verify('/models/a.gguf', 'sha256:aa', 'x', 'unknown-key'))->toBeFalse();

    Process::assertRan(fn ($process) => in_array('sign-blob', $process->command, true) && in_array('--key', $process->command, true));

    expect((new Signing)->for('cosign'))->toBeInstanceOf(CosignSigner::class)
        ->and(fn () => (new Signing)->for('gpg'))->toThrow(InvalidArgumentException::class);
});

// The base: pinned, mirrored, safetensors only, and each weight file against the checksum the Hub publishes.
function mirroredBase(string $revision = 'rev1', array $files = ['model.safetensors' => 'the weights', 'config.json' => '{}']): string
{
    $directory = config('ai.registry.mirror').'/models--org--base/snapshots/'.$revision;

    File::ensureDirectoryExists($directory);

    foreach ($files as $name => $content) {
        File::put($directory.'/'.$name, $content);
    }

    return $directory;
}

function hubTree(string $weights = 'the weights'): void
{
    Http::fake(['huggingface.co/api/models/org/base/tree/rev1*' => Http::response([
        ['type' => 'file', 'path' => 'config.json', 'oid' => 'git-sha1'],
        ['type' => 'file', 'path' => 'model.safetensors', 'oid' => 'git-sha1', 'lfs' => ['oid' => hash('sha256', $weights), 'size' => strlen($weights)]],
    ])]);
}

it('passes a base that is pinned, mirrored, safetensors only and matches the Hub', function () {
    mirroredBase();
    hubTree();

    $lines = (new BaseModelCheck)->check('org/base', 'rev1');

    expect($lines)->toHaveCount(1)->and($lines[0]->ok)->toBeTrue()->and($lines[0]->detail)->toBe('mirrored · checksums match Hub · safetensors only');
});

it('fails a base on each thing the book lists', function (string $case) {
    $revision = 'rev1';

    if ($case === 'unpinned') {
        $revision = null;
    }

    if ($case === 'pickled') {
        mirroredBase('rev1', ['model.safetensors' => 'the weights', 'pytorch_model.bin' => 'pickle']);
    }

    if ($case === 'changed') {
        mirroredBase();
        hubTree('different weights');
    }

    if ($case === 'hub down') {
        mirroredBase();
        Http::fake(['huggingface.co/*' => Http::response('', 503)]);
    }

    if ($case === 'no safetensors') {
        mirroredBase('rev1', ['config.json' => '{}']);
    }

    $line = (new BaseModelCheck)->check('org/base', $revision)[0];

    expect($line->ok)->toBeFalse()->and($line->detail)->toContain(match ($case) {
        'unpinned' => 'not pinned',
        'not mirrored' => 'not mirrored',
        'pickled' => 'safetensors only',
        'changed' => 'does not match the Hub',
        'hub down' => 'could not be compared',
        'no safetensors' => 'no .safetensors',
    });
})->with(['unpinned', 'not mirrored', 'pickled', 'changed', 'hub down', 'no safetensors']);

it('adds the base to what registry:verify checks when it is switched on', function () {
    config(['ai.registry.verify_base' => true, 'ai.registry.signing.required' => false]);

    ModelRegistryEntry::query()->update(['base' => 'org/base', 'base_revision' => 'rev1']);

    mirroredBase();
    hubTree();

    [$code, $output] = verifyOutput();

    expect($code)->toBe(0)->and($output)->toContain('base org/base@rev1')->toContain('mirrored · checksums match Hub');
});
