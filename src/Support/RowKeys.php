<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Turns primary key values into row tag suffixes that are equal whenever the database could
 * consider the values equal, or gives up on row tags when it cannot guarantee that.
 *
 * Integer keys compare numerically, so "05", 5 and 5.0 share one tag; any other value for an
 * integer key may be cast by the database (MySQL reads "5abc" as 5) and gives up. String keys
 * may use case-insensitive collations, so ASCII letters, digits, "-" and "_" are lowercased;
 * anything else, such as accents or padding that a collation may ignore, gives up. Too many
 * keys give up as well, bounding the number of tags a single query reads or writes.
 */
final class RowKeys
{
    public const int MAX_PER_TABLE = 100;

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>|null null when row tags cannot represent the values safely
     */
    public static function canonical(array $values, bool $stringKeys): ?array
    {
        if (count($values) > self::MAX_PER_TABLE) {
            return null;
        }

        $keys = [];
        foreach ($values as $value) {
            $key = $stringKeys ? self::stringKey($value) : self::integerKey($value);

            if ($key === null) {
                return null;
            }

            $keys[$key] = true;
        }

        if (count($keys) > self::MAX_PER_TABLE) {
            return null;
        }

        $canonical = array_map('strval', array_keys($keys));
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * Keys for the values a column of the type is compared with, such as a foreign key column
     * and the key it references, which are equal whenever the database could consider the
     * values equal. Null when one of the values cannot be keyed for the type, or there are none
     * or too many.
     *
     * @param  array<int|string, mixed>  $values
     * @return non-empty-list<string>|null
     */
    public static function forColumn(array $values, ColumnType $type): ?array
    {
        if ($values === [] || count($values) > self::MAX_PER_TABLE) {
            return null;
        }

        $keys = [];
        foreach ($values as $value) {
            $key = match ($type) {
                ColumnType::Integer => self::integerKey($value),
                ColumnType::Decimal => self::decimalKey($value),
                ColumnType::Text => self::stringKey($value),
                ColumnType::Uuid => self::uuidKey($value),
            };

            if ($key === null) {
                return null;
            }

            $keys[$key] = true;
        }

        $canonical = array_map('strval', array_keys($keys));
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * Decimals compare by value, so `1`, `'1.0'` and `'01.00'` share a key; an integer has the
     * key `integerKey()` gives it. Floats that are not whole numbers have no exact decimal
     * spelling, and other strings, such as `'1e2'` or `'1abc'`, may be cast.
     */
    private static function decimalKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return self::integerKey($value);
        }

        if (preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?\s*$/', $value, $matches) !== 1 || $matches[2].($matches[3] ?? '') === '') {
            return null;
        }

        $whole = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');
        $key = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return ($matches[1] === '-' && $key !== '0' ? '-' : '').$key;
    }

    /**
     * UUIDs compare by their 32 hexadecimal digits, whatever their case, hyphens or braces.
     */
    private static function uuidKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = str_replace('-', '', trim($value, '{}'));

        return strlen($digits) === 32 && ctype_xdigit($digits) ? strtolower($digits) : null;
    }

    private static function integerKey(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53 ? (string) (int) $value : null;
        }

        if (! is_string($value) || preg_match('/^\s*([+-]?)0*(\d+)\s*$/', $value, $matches) !== 1) {
            return null;
        }

        return ($matches[1] === '-' && $matches[2] !== '0' ? '-' : '').$matches[2];
    }

    private static function stringKey(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }
}
