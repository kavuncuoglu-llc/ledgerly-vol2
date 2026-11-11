<?php

namespace App\Ai\Training;

use Illuminate\Support\Facades\Process;
use RuntimeException;

// The `hf` command of the huggingface_hub package in training/'s environment, with HF_TOKEN from the environment of the process (a token with write access, for non-customer datasets only; Appendix L). Not run in the Volume 2 build: it needs a token and a Hub account.
final class HuggingFaceHub implements HubUploader
{
    public function upload(string $repository, array $files): string
    {
        $token = (string) env('HF_TOKEN');

        if ($token === '') {
            throw new RuntimeException('Set HF_TOKEN (a write token) to publish to the Hub.');
        }

        $revision = '';

        foreach ($files as $inRepository => $local) {
            $result = Process::path(base_path('training'))
                ->env(['HF_TOKEN' => $token])
                ->run(['uv', 'run', 'hf', 'upload', $repository, $local, $inRepository, '--repo-type', 'dataset', '--private']);

            if (! $result->successful()) {
                throw new RuntimeException('hf upload failed: '.trim($result->errorOutput()));
            }

            $revision = $this->commitIn($result->output());
        }

        return $revision;
    }

    private function commitIn(string $output): string
    {
        return preg_match('~/commit/([0-9a-f]{7,40})~', $output, $match) === 1
            ? substr($match[1], 0, 7)
            : 'unknown';
    }
}
