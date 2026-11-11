<?php

namespace App\Ai\Batch;

use App\Models\AiBatch;

// Chapter 37, Topic 7: what a batch needs from wherever it runs. A provider's batch API (half price, a day's window, off the interactive rate limits), or your own hardware while it is idle.
interface BatchDriver
{
    /** @return string the provider's id for the batch */
    public function submit(AiBatch $batch): string;

    /** @return string processing | completed | failed */
    public function status(AiBatch $batch): string;

    /** @return iterable<int, array{custom_id: string, response?: array, error?: string}> results come back in any order, and some fail on their own */
    public function results(AiBatch $batch): iterable;
}
