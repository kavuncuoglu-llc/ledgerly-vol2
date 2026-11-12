<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\ChatModel;
use InvalidArgumentException;

// Chapter 47: a driver by its short name, undecorated. The contract suite needs the driver itself, below the retries and the usage log, so it asks here and the classes stay inside app/Ai/Drivers (a test enforces that).
final class DriverFactory
{
    public const NAMES = ['raw', 'prism', 'ai-sdk'];

    public static function make(string $name, array $config): ChatModel
    {
        return match ($name) {
            'raw' => new OpenAiCompatibleDriver($config),
            'prism' => new PrismDriver($config),
            'ai-sdk' => new AiSdkDriver($config),
            default => throw new InvalidArgumentException("[{$name}] is not a driver: ".implode(', ', self::NAMES).'.'),
        };
    }
}
