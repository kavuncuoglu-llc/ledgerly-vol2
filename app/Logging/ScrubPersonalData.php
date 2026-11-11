<?php

namespace App\Logging;

use App\Ai\Privacy\PersonalDataDetector;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

// Chapter 34, Topic 6: a Monolog processor tapped onto every channel (config/logging.php), so a stray Log::info($message) is scrubbed before it is written. Irreversible: "[email]", "[card]".
final class ScrubPersonalData implements ProcessorInterface
{
    public function __construct(private PersonalDataDetector $detector) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->detector->mask($record->message),
            context: $this->scrubbed($record->context),
        );
    }

    private function scrubbed(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => $this->detector->mask($value),
            is_array($value) => $this->scrubbed($value),
            $value instanceof Throwable => $this->detector->mask((string) $value),   // an exception's message is whatever somebody put in it
            default => $value,
        }, $values);
    }
}
