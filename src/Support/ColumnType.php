<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * How a database compares a column with a value, which decides when two values name the same
 * row. An integer column compares numerically and casts what it is given, so `'05'` is 5 and
 * MySQL reads `'1abc'` as 1; a text column compares strings by its collation, but numerically
 * when given a number. A value is only keyed for a column in the way that column compares it.
 * Columns of any other type, such as decimals, dates or UUIDs, are not keyed at all.
 */
enum ColumnType: string
{
    case Integer = 'n';
    case Text = 's';

    private const array INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'];

    private const array TEXT_TYPES = [
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'character', 'character varying',
        'bpchar', 'citext', 'nchar', 'nvarchar', 'ntext',
    ];

    /**
     * @param  string  $declaredType  the type name the schema lists, such as `bigint`, `varchar(255)` or `int unsigned`
     */
    public static function of(string $declaredType): ?self
    {
        $name = trim((string) preg_replace('/\(.*$|\bunsigned\b/', '', strtolower($declaredType)));

        return match (true) {
            in_array($name, self::INTEGER_TYPES, true) => self::Integer,
            in_array($name, self::TEXT_TYPES, true) => self::Text,
            default => null,
        };
    }
}
