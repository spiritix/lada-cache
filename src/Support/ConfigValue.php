<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use InvalidArgumentException;

final class ConfigValue
{
    public static function integer(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        $parsed = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $parsed !== false ? $parsed : throw new InvalidArgumentException(sprintf('Invalid %s "%s", expected an integer', $key, self::describe($value)));
    }

    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : throw new InvalidArgumentException(sprintf('Invalid %s "%s", expected a string', $key, self::describe($value)));
    }

    /**
     * @return list<string>
     */
    public static function strings(string $key): array
    {
        $value = config($key, []);

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('Invalid %s "%s", expected a list of strings', $key, self::describe($value)));
        }

        return array_values(array_map(
            static fn (mixed $item): string => is_string($item) ? $item : throw new InvalidArgumentException(sprintf('Invalid %s entry "%s", expected a string', $key, self::describe($item))),
            $value,
        ));
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
