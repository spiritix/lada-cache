<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use UnexpectedValueException;

final class RedisReply
{
    public static function string(mixed $value, string $context): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => throw new UnexpectedValueException(sprintf('Malformed Lada Cache %s: expected a string, got %s', $context, get_debug_type($value))),
        };
    }

    public static function integer(mixed $value, string $context): int
    {
        $parsed = match (true) {
            is_int($value) => $value,
            is_string($value) => filter_var($value, FILTER_VALIDATE_INT),
            default => false,
        };

        return $parsed !== false ? $parsed : throw new UnexpectedValueException(sprintf('Malformed Lada Cache %s: expected an integer, got %s', $context, get_debug_type($value)));
    }
}
