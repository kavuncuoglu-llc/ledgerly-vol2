<?php

namespace App\Ai\Retrieval;

final readonly class CitationReport
{
    /**
     * @param  string[]  $errors
     * @param  array<string, RetrievedChunk>  $cited  label => chunk, for the labels that exist in this turn's map
     */
    public function __construct(public array $errors = [], public array $cited = []) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isGrounded(): bool
    {
        return ! $this->hasErrors() && $this->cited !== [];
    }
}
