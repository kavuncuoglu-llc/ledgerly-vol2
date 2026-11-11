<?php

namespace App\Ai\Cost\Audit;

// Chapter 35, Topic 9: one detector per postmortem, each a query over the same rows.
interface Signature
{
    public function name(): string;

    public function detect(AuditData $data): SignatureResult;
}
