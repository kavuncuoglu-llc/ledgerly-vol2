<?php

namespace App\Ai\Profile;

final readonly class ProfilePrompt
{
    public function __construct(public string $name, public string $text) {}
}
