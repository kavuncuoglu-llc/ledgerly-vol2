<?php

namespace App\Ai\Tools;

// Chapter 51, Topic 3: a tool whose request is refused by a rule before anyone is asked to approve it. The reason goes back to the model, which explains it; the model cannot argue it away.
interface RefusesBeforeApproval
{
    public function refusal(array $args, ToolContext $ctx): ?string;
}
