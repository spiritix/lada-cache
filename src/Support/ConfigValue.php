<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use InvalidArgumentException;
use Spiritix\LadaCache\CacheScope;

/**
 * Typed access to the Lada Cache configuration.
 *
 * Invalid values fail with a message naming the key instead of being silently
 * cast. An empty string, as an environment variable defined without a value
 * yields it, or null stands for the default.
 */
final class ConfigValue
{
    /**
     * Fail early, at boot, on any invalid value.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(): void
    {
        self::redisConnection();
        self::prefix();
        self::expirationTime();
        self::includeTables();
        self::excludeTables();
        self::scope();
    }

    public static function redisConnection(): string
    {
        return self::string('lada-cache.redis_connection', 'cache');
    }

    public static function prefix(): string
    {
        return self::string('lada-cache.prefix', 'lada:');
    }

    /**
     * Seconds until cached entries expire; 0 keeps them until invalidated.
     */
    public static function expirationTime(): int
    {
        return self::nonNegativeInteger('lada-cache.expiration_time', 0);
    }

    /**
     * @return list<string>
     */
    public static function includeTables(): array
    {
        return self::strings('lada-cache.include_tables');
    }

    /**
     * @return list<string>
     */
    public static function excludeTables(): array
    {
        return self::strings('lada-cache.exclude_tables');
    }

    public static function scope(): CacheScope
    {
        $value = config('lada-cache.scope');

        if ($value === null || $value === '') {
            return CacheScope::All;
        }

        $expected = 'one of '.implode(', ', array_column(CacheScope::cases(), 'value'));

        return (is_string($value) ? CacheScope::tryFrom($value) : null) ?? throw self::invalid('lada-cache.scope', $expected, $value);
    }

    private static function string(string $key, string $default): string
    {
        $value = config($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) ? $value : throw self::invalid($key, 'a string', $value);
    }

    private static function nonNegativeInteger(string $key, int $default): int
    {
        $value = config($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : $value;

        return is_int($parsed) && $parsed >= 0 ? $parsed : throw self::invalid($key, 'a non-negative integer', $value);
    }

    /**
     * @return list<string>
     */
    private static function strings(string $key): array
    {
        $value = config($key) ?? [];

        if (! is_array($value)) {
            throw self::invalid($key, 'a list of strings', $value);
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw self::invalid($key, 'a list of strings', $item, 'an entry ');
            }
        }

        return array_values($value);
    }

    private static function invalid(string $key, string $expected, mixed $value, string $what = ''): InvalidArgumentException
    {
        $actual = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new InvalidArgumentException("Invalid lada-cache configuration {$key}: expected {$expected}, got {$what}{$actual}.");
    }
}
