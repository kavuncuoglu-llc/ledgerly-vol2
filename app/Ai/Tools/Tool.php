<?php

namespace App\Ai\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return class-string the arguments DTO; SchemaBuilder turns it into `parameters` */
    public function arguments(): string;

    public function kind(): ToolKind;

    public function risk(): Risk;

    public function approver(): Approver;

    public function schemaName(): string;

    public function perConversationLimit(): int;

    public function describeIntent(array $args, ToolContext $ctx): string;

    public function describe(array $args, ToolContext $ctx): string;

    public function handle(object $args, ToolContext $ctx): ToolResult;
}
