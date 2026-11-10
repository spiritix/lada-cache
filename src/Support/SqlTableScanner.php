<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Finds the tables SQL text reads or writes, including those only named inside raw
 * expressions such as `whereRaw('exists (select 1 from pets ...)')` that the builder
 * structure does not reveal, and those written by trigger bodies. Words mistaken for tables
 * (`extract(year from created_at)`) only add tags nothing writes, which costs nothing.
 */
final class SqlTableScanner
{
    private const string LITERALS = "/'(?:[^'\\\\]|\\\\.|'')*'/s";

    private const string IDENTIFIER = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z_][\w$]*)';

    private const string QUALIFIED = self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')*';

    private const string WRITE = '/\b(?:insert\s+(?:(?:ignore|low_priority|delayed|high_priority)\s+)*(?:or\s+\w+\s+)?into|replace\s+(?:(?:low_priority|delayed)\s+)?into|update(?:\s+(?:low_priority|ignore|only))*|delete(?:\s+(?:low_priority|quick|ignore))*\s+from(?:\s+only)?|merge\s+into|truncate(?:\s+table)?(?:\s+only)?)\s+(?!(?:set|of|on|nowait|skip|table|only)\b)(?<name>'.self::QUALIFIED.')/i';

    /**
     * Every identifier following FROM or JOIN, and each item of a comma-separated FROM list.
     * Names keep their database or schema qualifier, without quotes and table prefix.
     *
     * @return list<string>
     */
    public static function tables(string $sql, string $tablePrefix): array
    {
        return array_map('strval', array_keys(self::tableReferenceCounts($sql, $tablePrefix)));
    }

    /**
     * How often the SQL reads each table, counting every FROM and JOIN item of the statement and
     * of its subqueries. A table read more than once, such as one joined to itself or named again
     * inside a subquery, is not limited to the rows a query's conditions pin.
     *
     * @return array<string, int>
     */
    public static function tableReferenceCounts(string $sql, string $tablePrefix): array
    {
        $item = self::QUALIFIED.'(?:\s+(?:as\s+)?(?!(?:where|on|using|join|inner|left|right|full|cross|natural|straight_join|group|order|limit|having|union|window|for|lateral)\b)'.self::IDENTIFIER.')?';

        preg_match_all('/\b(?:from|join|straight_join)\s+(?<list>'.$item.'(?:\s*,\s*'.$item.')*)/i', self::withoutLiterals($sql), $matches);

        $names = [];
        foreach ($matches['list'] as $list) {
            preg_match_all('/(?:^|,)\s*(?<name>'.self::QUALIFIED.')/', $list, $items);
            array_push($names, ...$items['name']);
        }

        $counts = [];
        foreach (self::tableNames($names, $tablePrefix) as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every table an INSERT, REPLACE, UPDATE, DELETE, MERGE or TRUNCATE in the SQL writes.
     *
     * @return list<string>
     */
    public static function writtenTables(string $sql, string $tablePrefix): array
    {
        preg_match_all(self::WRITE, self::withoutLiterals($sql), $matches);

        return array_values(array_unique(self::tableNames($matches['name'], $tablePrefix)));
    }

    public static function withoutLiterals(string $sql): string
    {
        return (string) preg_replace(self::LITERALS, "''", $sql);
    }

    /**
     * @param  list<string>  $qualifiedNames
     * @return list<string> One name per identifier, in order and with repeats
     */
    private static function tableNames(array $qualifiedNames, string $tablePrefix): array
    {
        $tables = [];
        foreach ($qualifiedNames as $qualified) {
            preg_match_all('/'.self::IDENTIFIER.'/', $qualified, $segments);
            $unquoted = array_map(static fn (string $segment): string => trim($segment, '`"[]'), $segments[0]);
            $table = (string) array_pop($unquoted);
            $tables[] = implode('.', [...$unquoted, TableName::withoutPrefix($table, $tablePrefix)]);
        }

        return $tables;
    }
}
