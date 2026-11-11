<?php

namespace App\Ai\Tools\Credentials;

use App\Ai\Tools\Tool;

// Chapter 33, Topic 5: one restricted key per external tool, named in config and read from the environment, never one key for the whole app. A tool with no key configured gets none and fails closed.
final class ToolCredentials
{
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('ai.tools.credentials', []));
    }

    public function has(Tool $tool): bool
    {
        return (string) ($this->config[$tool->name()]['key'] ?? '') !== '';
    }

    public function for(Tool $tool): Credential
    {
        $entry = $this->config[$tool->name()] ?? [];

        $secret = (string) ($entry['key'] ?? '');

        if ($secret === '') {
            throw new CredentialNotConfigured("No restricted key is configured for {$tool->name()}.");
        }

        return new Credential(
            label: (string) ($entry['label'] ?? "restricted key for {$tool->name()}"),
            scopes: (array) ($entry['scopes'] ?? []),
            secret: $secret,
        );
    }
}
