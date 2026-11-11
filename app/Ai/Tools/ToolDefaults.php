<?php

namespace App\Ai\Tools;

trait ToolDefaults
{
    public function perConversationLimit(): int
    {
        return 10;
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        return $this->name().' '.json_encode($args);
    }
}
