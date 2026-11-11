<?php

namespace App\Ai\Exceptions;

use Illuminate\Support\Facades\Context;
use RuntimeException;
use Throwable;

// Chapter 31, Topic 2: exceptions carry a pointer, not the payload. A validation error that quoted the model's invalid JSON, or an HTTP error that echoed a request body, would land in the error tracker; this one
// holds a short reason and the model_calls id, and the audit trail has the rest (redacted, access-controlled).
abstract class AiException extends RuntimeException
{
    public function __construct(string $reason, public readonly ?int $modelCallId = null, ?Throwable $previous = null)
    {
        parent::__construct($reason.($modelCallId ? " (model_call {$modelCallId})" : ''), previous: $previous);
    }

    // What Laravel's logger and error trackers receive.
    public function context(): array
    {
        return ['model_call_id' => $this->modelCallId, 'trace_id' => Context::get('trace_id')];
    }
}
