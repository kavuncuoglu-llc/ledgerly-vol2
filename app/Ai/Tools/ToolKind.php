<?php

namespace App\Ai\Tools;

enum ToolKind: string
{
    case Read = 'read';
    case WriteLowRisk = 'write_low_risk';
    case WriteHighRisk = 'write_high_risk';

    public function risk(): Risk
    {
        return match ($this) {
            self::Read => Risk::Read,
            self::WriteLowRisk => Risk::LowWrite,
            self::WriteHighRisk => Risk::HighWrite,
        };
    }

    public function isWrite(): bool
    {
        return $this !== self::Read;
    }
}
