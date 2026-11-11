<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

// Eloquent <-> pgvector: a float array in PHP, the '[0.1,0.2,...]' literal in the database
final class Vector implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $inside = trim((string) $value, '[]');

        if ($inside === '') {
            return [];
        }

        return array_map('floatval', explode(',', $inside));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::literal($value);
    }

    public static function literal(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
