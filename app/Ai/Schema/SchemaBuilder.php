<?php

namespace App\Ai\Schema;

use App\Ai\Structured\ExtractionMeta;
use BackedEnum;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

final class SchemaBuilder
{
    private const SCALARS = [
        'string' => 'string',
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
    ];

    public static function for(string $class): array
    {
        $properties = [];

        foreach (self::parameters($class) as $parameter) {
            $properties[$parameter->getName()] = self::property($class, $parameter);
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    public static function name(string $class): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($class)));
    }

    private static function parameters(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $parameters = $constructor
            ? $constructor->getParameters()
            : [];

        return array_values(array_filter($parameters, fn (ReflectionParameter $parameter) => ! self::isMeta($parameter)));
    }

    private static function isMeta(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        return $type->getName() === ExtractionMeta::class;
    }

    private static function property(string $class, ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new InvalidArgumentException("{$class}::\${$parameter->getName()} needs a single named type so a schema can be generated.");
        }

        $describe = self::describe($parameter);

        $schema = self::typeSchema($class, $parameter, $type, $describe);

        if ($describe) {
            $schema['description'] = $describe->description;
        }

        return $schema;
    }

    private static function describe(ReflectionParameter $parameter): ?Describe
    {
        $attributes = $parameter->getAttributes(Describe::class);

        return $attributes === []
            ? null
            : $attributes[0]->newInstance();
    }

    private static function typeSchema(string $class, ReflectionParameter $parameter, ReflectionNamedType $type, ?Describe $describe): array
    {
        $name = $type->getName();

        if (isset(self::SCALARS[$name])) {
            return ['type' => self::nullable(self::SCALARS[$name], $type)];
        }

        if ($name === 'array') {
            return self::arraySchema($class, $parameter, $type, $describe);
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            return self::enumSchema($name, $type);
        }

        if (class_exists($name)) {
            $schema = self::for($name);

            return $type->allowsNull()
                ? ['anyOf' => [$schema, ['type' => 'null']]]
                : $schema;
        }

        throw new InvalidArgumentException("{$class}::\${$parameter->getName()} has a type ({$name}) that cannot be described in JSON Schema.");
    }

    private static function nullable(string $json, ReflectionNamedType $type): string|array
    {
        return $type->allowsNull()
            ? [$json, 'null']
            : $json;
    }

    private static function enumSchema(string $enum, ReflectionNamedType $type): array
    {
        $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());

        $json = is_int($values[0])
            ? 'integer'
            : 'string';

        if ($type->allowsNull()) {
            $values[] = null;
        }

        return ['type' => self::nullable($json, $type), 'enum' => $values];
    }

    private static function arraySchema(string $class, ReflectionParameter $parameter, ReflectionNamedType $type, ?Describe $describe): array
    {
        $items = $describe?->items ?? self::itemsFromDocblock($class, $parameter->getName());

        if ($items === null) {
            throw new InvalidArgumentException("{$class}::\${$parameter->getName()} is an array of unknown items. Add #[Describe(items: ...)] or a @var Type[] docblock.");
        }

        $schema = [
            'type' => self::nullable('array', $type),
            'items' => self::itemSchema($class, $items),
        ];

        return $schema;
    }

    private static function itemsFromDocblock(string $class, string $property): ?string
    {
        $comment = (new ReflectionProperty($class, $property))->getDocComment();

        if (! $comment) {
            return null;
        }

        if (! preg_match('/@var\s+([\\\\\w]+)\[\]/', $comment, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function itemSchema(string $class, string $items): array
    {
        if (isset(self::SCALARS[$items])) {
            return ['type' => self::SCALARS[$items]];
        }

        $namespaced = (new ReflectionClass($class))->getNamespaceName().'\\'.$items;

        foreach ([$items, $namespaced] as $candidate) {
            if (class_exists($candidate)) {
                return self::for($candidate);
            }
        }

        throw new InvalidArgumentException("Cannot find the item class {$items} for {$class}.");
    }
}
