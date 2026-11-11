<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final class StatementShape
{
    private const int MAX_BYTES = 160;

    /**
     * The statement without what varies between its executions, so the metrics group them:
     * string and number literals become `?`, lists of placeholders one, runs of whitespace a
     * space, and the rest is cut off after `MAX_BYTES`.
     */
    public static function of(string $sql): string
    {
        $shape = preg_replace(
            ["/'(?:[^'\\\\]|\\\\.|'')*'/", '/\b\d+(?:\.\d+)?\b/', '/\s+/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/'],
            ['?', '?', ' ', '(?)'],
            $sql,
        );

        return mb_strcut(trim($shape ?? $sql), 0, self::MAX_BYTES);
    }
}
