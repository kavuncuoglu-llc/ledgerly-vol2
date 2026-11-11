<?php

namespace App\Ai\Tools;

enum Approver: string
{
    case Customer = 'customer';
    case Agent = 'agent';
}
