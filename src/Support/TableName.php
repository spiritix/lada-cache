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

    /**
     * The name a table is tagged by: without the connection's table prefix and in lower case.
     * Whether `Owners` and `owners` are one table depends on the database, its settings and how
     * the name is quoted, none of which the SQL shows, so names differing only in case share
     * their tags; where they are two tables, a write to one merely refreshes reads of the other.
     */
    public static function tagged(string $table, string $prefix = ''): string
    {
        return strtolower(self::withoutPrefix($table, $prefix));
    }

    public static function unqualified(string $table): string
    {
        $position = strrpos($table, '.');

        return $position === false ? $table : substr($table, $position + 1);
    }
}
