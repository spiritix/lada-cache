<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Splits a query builder's `table as alias` the way Laravel's grammar does
 * (`Grammar::wrapAliasedTable()`), so Lada reads the same table and alias the database is sent.
 */
final class SqlAliasParser
{
    public static function stripAlias(string $table): string
    {
        return self::split($table)[0];
    }

    public static function extractAlias(string $table): ?string
    {
        return self::split($table)[1];
    }

    /**
     * @return array{string, string|null}
     */
    private static function split(string $table): array
    {
        $segments = preg_split('/\s+as\s+/i', $table);

        if ($segments === false || count($segments) !== 2) {
            return [$table, null];
        }

        return [$segments[0], $segments[1]];
    }
}
