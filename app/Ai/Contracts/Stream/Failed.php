<?php

namespace App\Ai\Contracts\Stream;

use Throwable;

// Yielded by a driver that wants to hand the failure to the consumer instead of throwing from inside the generator; the drivers in this repository throw.
final readonly class Failed implements StreamEvent
{
    public function __construct(public Throwable $error) {}
}
