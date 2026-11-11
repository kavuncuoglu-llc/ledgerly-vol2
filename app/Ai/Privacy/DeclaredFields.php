<?php

namespace App\Ai\Privacy;

use InvalidArgumentException;

// Chapter 34, Topic 3: minimize before you redact. A prompt class declares the fields it puts in front of a model, as name => null for a field that identifies nobody, or name => the reason for one that identifies a person.
// `select` is the only way a record becomes prompt text: a new column on the model changes nothing until somebody declares it, in a diff a reviewer reads.
final class DeclaredFields
{
    private const IDENTIFYING = '/name|email|phone|address|contact|client|tax|birth|ssn|iban|card|notes?$/i';

    /**
     * @param  array<string, mixed>  $available  everything the caller could say
     * @param  array<string, string|null>  $declared  field => null, or why a person's data is needed
     * @return array<string, mixed> only the declared fields, in the declared order
     */
    public static function select(array $available, array $declared): array
    {
        $selected = [];

        foreach (array_keys($declared) as $field) {
            if (! array_key_exists($field, $available)) {
                throw new InvalidArgumentException("The prompt declares the field '{$field}', but nothing supplies it.");
            }

            $selected[$field] = $available[$field];
        }

        return $selected;
    }

    /** @return string[] declared fields that look like they identify a person but have no reason written beside them */
    public static function unexplained(array $declared): array
    {
        return array_values(array_filter(
            array_keys($declared),
            fn (string $field) => preg_match(self::IDENTIFYING, $field) === 1 && trim((string) $declared[$field]) === '',
        ));
    }

    /** @return string[] */
    public static function personal(array $declared): array
    {
        return array_keys(array_filter($declared, fn ($reason) => trim((string) $reason) !== ''));
    }
}
