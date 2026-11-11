<?php

namespace App\Logging;

use App\Ai\Privacy\PersonalDataDetector;

// Chapter 34, Topic 6: the backstop in an error tracker's `before_send`. The tracker's own scrubbing stays on (Sentry's send_default_pii off, Flare's censored request fields); this runs the same detectors on whatever is left.
// Wire it as 'before_send' => [ErrorReportScrubber::class, 'beforeSend'] in config/sentry.php. It takes the SDK's event object or a plain array, so nothing here depends on one vendor.
final class ErrorReportScrubber
{
    public function __construct(private PersonalDataDetector $detector) {}

    public static function beforeSend(mixed $event): mixed
    {
        return app(self::class)($event);
    }

    public function __invoke(mixed $event): mixed
    {
        if (is_array($event)) {
            return $this->scrubArray($event);
        }

        if (! is_object($event)) {
            return $event;
        }

        $this->scrubObject($event);

        return $event;
    }

    public function scrubArray(array $payload): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => $this->detector->mask($value),
            is_array($value) => $this->scrubArray($value),
            default => $value,
        }, $payload);
    }

    private function scrubObject(object $event): void
    {
        if (method_exists($event, 'getMessage') && method_exists($event, 'setMessage')) {
            $message = $event->getMessage();

            if (is_string($message)) {
                $event->setMessage($this->detector->mask($message));
            }
        }

        foreach (['Extra', 'Request', 'Tags', 'Contexts'] as $part) {
            if (method_exists($event, "get{$part}") && method_exists($event, "set{$part}")) {
                $event->{"set{$part}"}($this->scrubArray((array) $event->{"get{$part}"}()));
            }
        }

        if (method_exists($event, 'getExceptions') && method_exists($event, 'setExceptions')) {
            $event->setExceptions(array_map(function (object $exception) {
                if (method_exists($exception, 'getValue') && method_exists($exception, 'setValue')) {
                    $exception->setValue($this->detector->mask((string) $exception->getValue()));
                }

                return $exception;
            }, $event->getExceptions()));
        }
    }
}
