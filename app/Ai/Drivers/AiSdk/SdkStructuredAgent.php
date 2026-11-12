<?php

namespace App\Ai\Drivers\AiSdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

// The same throwaway agent, for a request that wants its reply as JSON that validates against a schema (Chapter 10).
class SdkStructuredAgent extends SdkAgent implements HasStructuredOutput
{
    public function __construct(
        string $instructions,
        array $messages,
        array $tools,
        array $sampling,
        array $providerOptions,
        private readonly array $jsonSchema,
    ) {
        parent::__construct($instructions, $messages, $tools, $sampling, $providerOptions);
    }

    public function schema(JsonSchema $schema): array
    {
        return SdkSchema::properties($schema, $this->jsonSchema);
    }
}
