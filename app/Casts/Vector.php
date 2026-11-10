<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * pgvector stores a vector as text like "[0.1,0.2,0.3]".
 * This cast turns it into a PHP array of floats and back.
 */
class Vector implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_map('floatval', explode(',', trim($value, '[]')));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toSql($value);
    }

    /** @param  array<int, float>  $vector */
    public static function toSql(array $vector): string
    {
        return '['.implode(',', array_map('floatval', $vector)).']';
    }
}
