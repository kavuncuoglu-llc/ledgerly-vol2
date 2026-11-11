<?php

namespace App\Ai\Tools;

final class RawSchemaValidator
{
    public static function errors(array $schema, array $args): array
    {
        $errors = [];

        $properties = $schema['properties'] ?? [];

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $args)) {
                $errors[$key][] = "The {$key} field is required.";
            }
        }

        foreach ($args as $key => $value) {
            if (! array_key_exists($key, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[$key][] = "The {$key} field is not allowed.";
                }

                continue;
            }

            if (! self::matches($properties[$key]['type'] ?? null, $value)) {
                $errors[$key][] = "The {$key} field has the wrong type.";
            }
        }

        return $errors;
    }

    private static function matches(string|array|null $type, mixed $value): bool
    {
        if ($type === null) {
            return true;
        }

        foreach ((array) $type as $candidate) {
            if (self::is($candidate, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function is(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }
}
