<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final class RawStatementClassifier
{
    private const string TABLE = '(?<table>[`"\[]?[\w$]+[`"\]]?(?:\.[`"\[]?[\w$]+[`"\]]?)?)';

    private const array WRITE_KEYWORDS = ['insert', 'replace', 'update', 'delete', 'merge'];

    private const array SIDE_EFFECT_FREE_KEYWORDS = [
        'select', 'show', 'explain', 'describe', 'desc', 'values', 'set', 'begin', 'start', 'commit',
        'rollback', 'savepoint', 'release', 'use', 'pragma', 'analyze', 'optimize', 'check', 'checksum',
        'lock', 'unlock', 'vacuum', 'reset', 'discard', 'listen', 'unlisten', 'notify', 'prepare',
        'deallocate', 'xa',
    ];

    private const array TABLE_WRITE_PATTERNS = [
        '/^insert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:or\s+\w+\s+)?(?:into\s+)?'.self::TABLE.'(?=[\s(]|$)/i',
        '/^replace\s+(?:(?:low_priority|delayed)\s+)*(?:into\s+)?'.self::TABLE.'(?=[\s(]|$)/i',
        '/^update\s+(?:(?:low_priority|ignore|only)\s+)*'.self::TABLE.'(?:\s+(?:as\s+)?(?!set\b)\w+)?\s+set\s/i',
        '/^delete\s+(?:(?:low_priority|quick|ignore)\s+)*from\s+(?:only\s+)?'.self::TABLE.'(?:\s+(?:as\s+)?(?!(?:where|using|order|limit|returning)\b)\w+)?(?:\s+(?:where|using|order|limit|returning)\b|\s*$)/i',
        '/^truncate\s+(?:table\s+)?(?:only\s+)?'.self::TABLE.'(?:\s+(?:restart|continue)\s+identity)?(?:\s+restrict)?\s*$/i',
    ];

    /**
     * Classifies SQL passed to `select()` and friends: reads unless it clearly writes.
     */
    public static function classifyQuery(string $sql, string $tablePrefix): RawStatementEffect
    {
        $statement = self::normalize($sql);
        if (! in_array(self::firstKeyword($statement), self::WRITE_KEYWORDS, true)) {
            return RawStatementEffect::none();
        }

        return self::classifyWrite($statement, $tablePrefix);
    }

    /**
     * Classifies SQL passed to `statement()`, `affectingStatement()` and `unprepared()`: anything
     * not known to be side-effect free or a write to a single table changes everything.
     */
    public static function classifyStatement(string $sql, string $tablePrefix): RawStatementEffect
    {
        $statement = self::normalize($sql);

        if ($statement === '') {
            return RawStatementEffect::none();
        }

        if (self::containsMultipleStatements($statement)) {
            return RawStatementEffect::everything();
        }

        $keyword = self::firstKeyword($statement);

        if (in_array($keyword, self::SIDE_EFFECT_FREE_KEYWORDS, true) || self::isTemporaryTableStatement($statement)) {
            return RawStatementEffect::none();
        }

        return self::classifyWrite($statement, $tablePrefix);
    }

    private static function classifyWrite(string $statement, string $tablePrefix): RawStatementEffect
    {
        foreach (self::TABLE_WRITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $statement, $matches) !== 1) {
                continue;
            }

            $table = $matches['table'];

            if (str_contains($table, '.')) {
                return RawStatementEffect::everything();
            }

            $table = trim($table, '`"[]');

            if ($tablePrefix !== '' && str_starts_with($table, $tablePrefix)) {
                $table = substr($table, strlen($tablePrefix));
            }

            return RawStatementEffect::writesTable($table);
        }

        return RawStatementEffect::everything();
    }

    private static function normalize(string $sql): string
    {
        $statement = ltrim($sql);

        while (true) {
            if (str_starts_with($statement, '--') || str_starts_with($statement, '#')) {
                $newline = strpos($statement, "\n");
                $statement = $newline === false ? '' : ltrim(substr($statement, $newline + 1));

                continue;
            }

            if (str_starts_with($statement, '/*')) {
                $end = strpos($statement, '*/');
                $statement = $end === false ? '' : ltrim(substr($statement, $end + 2));

                continue;
            }

            if (str_starts_with($statement, '(')) {
                $statement = ltrim(substr($statement, 1));

                continue;
            }

            break;
        }

        return rtrim(rtrim($statement), ';');
    }

    private static function firstKeyword(string $statement): string
    {
        return preg_match('/^[a-z]+/i', $statement, $matches) === 1 ? strtolower($matches[0]) : '';
    }

    private static function isTemporaryTableStatement(string $statement): bool
    {
        return preg_match('/^(?:create|drop)\s+(?:global\s+|local\s+)?temp(?:orary)?\s+table\b/i', $statement) === 1;
    }

    private static function containsMultipleStatements(string $statement): bool
    {
        $quote = null;
        $length = strlen($statement);

        for ($index = 0; $index < $length; $index++) {
            $character = $statement[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === ';') {
                return true;
            }
        }

        return false;
    }
}
