<?php

namespace App\Ai\Contracts\Capabilities;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;

// For a provider whose own tool-use is the strongest way to get JSON (Anthropic). No driver in this repository implements it yet: it is the seam, with response_format as the fallback.
interface SupportsNativeStructuredOutput
{
    public function chatStructured(ChatRequest $request, array $schema): ChatResponse;
}
