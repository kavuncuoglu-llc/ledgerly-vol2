<?php

namespace App\Ai\Training\TemplateCheck;

interface TemplateRenderer
{
    public function name(): string;

    /** @param list<array> $messages the prompt's messages, as the training row has them */
    public function render(array $messages): RenderedPrompt;
}
