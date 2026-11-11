<?php

namespace App\Ai\Registry;

use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 44, Topic 8: a base from the Hub is pinned to a revision, mirrored in your own storage, only safetensors, and each weight file matches the checksum the Hub publishes for it. The mirror is a directory (config('ai.registry.mirror')): either the Hugging Face cache layout
// (`models--org--name/snapshots/<revision>/`, what `huggingface-cli download` and `from_pretrained` write) or `<org>/<name>@<revision>/`.
class BaseModelCheck
{
    /** @return list<VerificationLine> */
    public function check(string $repository, ?string $revision): array
    {
        $subject = "base {$repository}@".($revision ?? '<no revision>');

        if (blank($revision)) {
            return [new VerificationLine($subject, false, 'the revision is not pinned: a branch name can move')];
        }

        $directory = $this->mirrored($repository, $revision);

        if ($directory === null) {
            return [new VerificationLine($subject, false, 'not mirrored: '.(config('ai.registry.mirror') ? 'no copy of this revision under '.config('ai.registry.mirror') : 'config(ai.registry.mirror) is not set').'; production must not download from the internet')];
        }

        $weights = $this->weights($directory);

        if ($weights['unsafe'] !== []) {
            return [new VerificationLine($subject, false, 'pickled weights present ('.implode(', ', $weights['unsafe']).'): safetensors only')];
        }

        if ($weights['safe'] === []) {
            return [new VerificationLine($subject, false, 'the mirror holds no .safetensors weights')];
        }

        return [$this->checksums($subject, $repository, $revision, $weights['safe'])];
    }

    private function mirrored(string $repository, string $revision): ?string
    {
        $root = rtrim((string) config('ai.registry.mirror'), '/');

        if ($root === '') {
            return null;
        }

        foreach ([$root.'/models--'.str_replace('/', '--', $repository).'/snapshots/'.$revision, $root.'/'.$repository.'@'.$revision] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{safe: list<string>, unsafe: list<string>} */
    private function weights(string $directory): array
    {
        $safe = [];

        $unsafe = [];

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            if (str_ends_with($file, '.safetensors')) {
                $safe[] = $directory.'/'.$file;
            }

            if (preg_match('/\.(bin|pt|pth|pkl|ckpt)$/', $file)) {
                $unsafe[] = $file;
            }
        }

        return ['safe' => $safe, 'unsafe' => $unsafe];
    }

    private function checksums(string $subject, string $repository, string $revision, array $files): VerificationLine
    {
        try {
            $tree = Http::timeout(15)->acceptJson()->get(rtrim((string) config('ai.registry.hub', 'https://huggingface.co'), '/')."/api/models/{$repository}/tree/{$revision}", ['recursive' => 'true'])->throw()->json();
        } catch (Throwable $e) {
            return new VerificationLine($subject, false, 'the Hub did not answer, so the checksums could not be compared: '.$e->getMessage());
        }

        $published = collect($tree)->filter(fn ($item) => ($item['type'] ?? null) === 'file' && isset($item['lfs']['oid']))->mapWithKeys(fn ($item) => [basename($item['path']) => $item['lfs']['oid']]);

        foreach ($files as $file) {
            $expected = $published[basename($file)] ?? null;

            if ($expected === null) {
                return new VerificationLine($subject, false, basename($file).' is not a file the Hub publishes a checksum for at this revision');
            }

            if (hash_file('sha256', $file) !== $expected) {
                return new VerificationLine($subject, false, basename($file).' does not match the Hub\'s checksum');
            }
        }

        return new VerificationLine($subject, true, 'mirrored · checksums match Hub · safetensors only');
    }
}
