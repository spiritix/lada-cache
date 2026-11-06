<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use InvalidArgumentException;

final class IntegerConfig
{
    public static function read(string $key, int $default): int
    {
        $value = config($key, $default);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(sprintf('Invalid %s "%s", expected an integer', $key, is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (int) $value;
    }
}
