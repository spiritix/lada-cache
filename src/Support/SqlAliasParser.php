<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Small helper to parse SQL aliases from table/Expression references.
 */
final class SqlAliasParser
{
    public static function stripAlias(string $table): string
    {
        return (string) preg_replace('/\s+as\s+[A-Za-z0-9_\.]+$/i', '', $table);
    }

    public static function extractAlias(string $table): ?string
    {
        return preg_match('/\s+as\s+([A-Za-z0-9_\.]+)$/i', $table, $m) === 1 ? $m[1] : null;
    }

    public static function extractAliasFromExpression(string $expr): ?string
    {
        return preg_match('/\bas\s+[`"\[]?([A-Za-z0-9_\.]+)[`"\]]?\s*$/i', $expr, $m) === 1 ? $m[1] : null;
    }
}
