<?php

namespace App\Ai\Batch;

use App\Models\AiBatch;

// For tests and for a first look at the commands: scripted results, no provider. `succeed()` and `fail()` say what comes back for a custom id; anything else succeeds with a short answer.
final class FakeBatchDriver implements BatchDriver
{
    /** @var array<string, string> */
    public array $failures = [];

    public string $status = 'completed';

    /** @var array<int, string> */
    public array $submitted = [];

    public function submit(AiBatch $batch): string
    {
        $this->submitted[] = (string) $batch->input_path;

        return 'fake-'.$batch->id;
    }

    public function status(AiBatch $batch): string
    {
        return $this->status;
    }

    public function results(AiBatch $batch): iterable
    {
        foreach ($batch->items()->orderBy('id')->get() as $item) {
            if (isset($this->failures[$item->custom_id])) {
                yield ['custom_id' => $item->custom_id, 'error' => $this->failures[$item->custom_id]];

                continue;
            }

            yield ['custom_id' => $item->custom_id, 'response' => ['content' => '4', 'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 20], 'model' => 'fake']];
        }
    }
}
