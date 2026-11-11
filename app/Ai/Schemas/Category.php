<?php

namespace App\Ai\Schemas;

enum Category: string
{
    case Billing = 'billing';
    case HowTo = 'howto';
    case Bug = 'bug';
    case Account = 'account';
    case Other = 'other';
}
