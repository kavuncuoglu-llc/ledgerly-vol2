<?php

namespace App\Evals\FailureModes;

use App\Evals\GoldenCase;

final readonly class Failure
{
    public const FACT_MISSING = 'required fact missing';

    public const WRONG_STRUCTURE = 'wrong structure';

    public const WRONGFUL_ESCALATION = 'wrongful escalation';

    public const MISSED_ESCALATION = 'missed escalation';

    public const TOOL_NOT_CALLED = 'tool not called';

    public function __construct(
        public GoldenCase $case,
        public FailureBucket $bucket,
        public string $detail,
        public string $reason,
        public bool $judged = false,
    ) {}

    public function toArray(): array
    {
        return [
            'case' => $this->case->id,
            'source' => $this->case->source,
            'category' => $this->case->category,
            'bucket' => $this->bucket->value,
            'detail' => $this->detail,
            'reason' => $this->reason,
            'judged' => $this->judged,
        ];
    }
}
