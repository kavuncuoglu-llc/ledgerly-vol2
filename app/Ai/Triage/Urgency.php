<?php

namespace App\Ai\Triage;

enum Urgency: string
{
    case Urgent = 'urgent';
    case Normal = 'normal';
}
