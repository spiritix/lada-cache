<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final class TableName
{
    /**
     * Turns a table name as the database knows it into the name Lada tags it by, which is the
     * name the query builder uses before the connection's table prefix is applied.
     */
    public static function withoutPrefix(string $table, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
