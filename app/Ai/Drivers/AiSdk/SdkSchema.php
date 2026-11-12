<?php

namespace App\Ai\Drivers\AiSdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

// Chapter 47, Topic 8: the SDK describes a tool's parameters with Laravel's JsonSchema builder, not with a JSON Schema array, so the array the app's tool definitions carry is rebuilt as builder types.
final class SdkSchema
{
    /** @return array<string, Type> */
    public static function properties(JsonSchema $schema, array $json): array
    {
        $required = $json['required'] ?? [];

        $types = [];

        foreach ($json['properties'] ?? [] as $name => $property) {
            $type = self::type($schema, $property);

            $types[$name] = in_array($name, $required, true)
                ? $type->required()
                : $type;
        }

        return $types;
    }

    public static function type(JsonSchema $schema, array $json): Type
    {
        $kinds = (array) ($json['type'] ?? 'string');

        $nullable = in_array('null', $kinds, true);

        $kind = array_values(array_diff($kinds, ['null']))[0] ?? 'string';

        $type = match ($kind) {
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'boolean' => $schema->boolean(),
            'array' => $schema->array()->items(self::type($schema, $json['items'] ?? ['type' => 'string'])),
            'object' => $schema->object(self::properties($schema, $json)),
            default => $schema->string(),
        };

        if (isset($json['description'])) {
            $type->description((string) $json['description']);
        }

        if (isset($json['enum'])) {
            $type->enum($json['enum']);
        }

        if ($nullable) {
            $type->nullable();
        }

        return $type;
    }
}
