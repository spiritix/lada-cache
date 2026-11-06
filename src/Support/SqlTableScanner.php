<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Finds the tables a compiled SELECT reads, including those only named inside raw expressions
 * such as `whereRaw('exists (select 1 from pets ...)')` that the builder structure does not
 * reveal. Every identifier following FROM or JOIN, and each item of a comma-separated FROM
 * list, counts; words that are not tables (`extract(year from created_at)`) only add tags
 * nothing writes, which costs nothing.
 */
final class SqlTableScanner
{
    private const string LITERALS = "/'(?:[^'\\\\]|\\\\.|'')*'/s";

    private const string IDENTIFIER = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z_][\w$]*)';

    /**
     * @return list<string>
     */
    public static function tables(string $sql, string $tablePrefix): array
    {
        $code = (string) preg_replace(self::LITERALS, "''", $sql);
        $qualified = self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')*';
        $item = $qualified.'(?:\s+(?:as\s+)?(?!(?:where|on|using|join|inner|left|right|full|cross|natural|straight_join|group|order|limit|having|union|window|for|lateral)\b)'.self::IDENTIFIER.')?';

        preg_match_all('/\b(?:from|join|straight_join)\s+(?<list>'.$item.'(?:\s*,\s*'.$item.')*)/i', $code, $matches);

        $tables = [];
        foreach ($matches['list'] as $list) {
            preg_match_all('/(?:^|,)\s*(?<name>'.$qualified.')/', $list, $names);

            foreach ($names['name'] as $name) {
                $table = self::withoutPrefix(self::lastSegment($name), $tablePrefix);
                $tables[$table] = true;
            }
        }

        return array_map('strval', array_keys($tables));
    }

    private static function lastSegment(string $qualified): string
    {
        preg_match_all('/'.self::IDENTIFIER.'/', $qualified, $segments);
        $last = (string) end($segments[0]);

        return trim($last, '`"[]');
    }

    private static function withoutPrefix(string $table, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
