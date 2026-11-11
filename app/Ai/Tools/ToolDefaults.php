<?php

namespace App\Ai\Tools;

trait ToolDefaults
{
    public function perConversationLimit(): int
    {
        return 10;
    }

    public function risk(): Risk
    {
        return $this->kind()->risk();                                       // Chapter 33: a tool declares its effect once, as kind(); risk() is the same declaration under the chapter's name
    }

    public function approver(): Approver
    {
        return Approver::Customer;
    }

    public function schemaName(): string
    {
        return 'tool.'.$this->name();
    }

    public function describe(array $args, ToolContext $ctx): string
    {
        return $this->describeIntent($args, $ctx);                          // written by the tool's code from validated arguments, never by the model
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        return $this->name().' '.json_encode($args);
    }
}
