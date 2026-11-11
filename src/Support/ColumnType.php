<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * How a database compares a column with a value, which decides when two values name the same
 * row. An integer column compares numerically and casts what it is given, so `'05'` is 5 and
 * MySQL reads `'1abc'` as 1; a decimal column does the same with fractions, so `'1.50'` is 1.5;
 * a text column compares strings by its collation, but numerically when given a number; a UUID
 * column ignores case, hyphens and braces. A value is only keyed for a column in the way that
 * column compares it. Columns of any other type, such as dates or binary strings, are not keyed
 * at all.
 *
 * The scripts read the keys of a column in the order of the cases (see `ColumnKeys::arguments()`),
 * in which the keys of a type may repeat those of the one before it.
 */
enum ColumnType: string
{
    case Integer = 'n';
    case Decimal = 'd';
    case Text = 's';
    case Uuid = 'u';

    private const array INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'];

    private const array DECIMAL_TYPES = ['decimal', 'numeric', 'dec', 'fixed'];

    private const array UUID_TYPES = ['uuid', 'uniqueidentifier'];

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
            in_array($name, self::DECIMAL_TYPES, true) => self::Decimal,
            in_array($name, self::TEXT_TYPES, true) => self::Text,
            in_array($name, self::UUID_TYPES, true) => self::Uuid,
            default => null,
        };
    }
}
