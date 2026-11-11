<?php

namespace App\Ai\Tools;

interface ToolSource
{
    /** @return Tool[] */
    public function tools(): array;
}
