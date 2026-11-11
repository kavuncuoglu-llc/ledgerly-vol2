<?php

namespace App\Ai\Agents;

use App\Ai\Streaming\StreamListener;

interface StreamsReplies
{
    public function listener(): ?StreamListener;
}
