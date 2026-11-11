<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Completed = 'completed';
    case Paused = 'paused';
    case Failed = 'failed';
    case Truncated = 'truncated';
    case Blocked = 'blocked';
}
