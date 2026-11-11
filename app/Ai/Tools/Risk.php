<?php

namespace App\Ai\Tools;

enum Risk: string
{
    case Read = 'read';
    case LowWrite = 'low_write';
    case HighWrite = 'high_write';
}
