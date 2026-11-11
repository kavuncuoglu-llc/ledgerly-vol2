<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case WaitingOnCustomer = 'waiting';
    case Escalated = 'escalated';
    case Solved = 'solved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::WaitingOnCustomer => 'Waiting on you',
            self::Escalated => 'Escalated',
            self::Solved => 'Solved',
        };
    }
}
