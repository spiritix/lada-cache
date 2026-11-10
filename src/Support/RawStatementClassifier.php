<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use LogicException;

final class RawStatementClassifier
{
    private const string TABLE = '(?<table>[`"\[]?[\w$]+[`"\]]?(?:\.[`"\[]?[\w$]+[`"\]]?)?)';

    private const string WRITE_KEYWORD_PATTERN = '/\b(?:insert|replace|update|delete|merge|truncate)\b/i';

    private const array SIDE_EFFECT_FREE_KEYWORDS = [
        'select', 'show', 'explain', 'describe', 'desc', 'values', 'table', 'set', 'begin', 'start', 'commit',
        'rollback', 'savepoint', 'release', 'use', 'pragma', 'analyze', 'optimize', 'check', 'checksum',
        'lock', 'unlock', 'vacuum', 'reset', 'discard', 'listen', 'unlisten', 'notify', 'prepare',
        'deallocate', 'xa',
    ];

    private const array TABLE_WRITE_PATTERNS = [
        'insert' => '/^insert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:or\s+\w+\s+)?(?:into\s+)?'.self::TABLE.'(?=[\s(]|$)/i',
        'replace' => '/^replace\s+(?:(?:low_priority|delayed)\s+)*(?:into\s+)?'.self::TABLE.'(?=[\s(]|$)/i',
        'update' => '/^update\s+(?:(?:low_priority|ignore|only)\s+)*'.self::TABLE.'(?:\s+(?:as\s+)?(?!set\b)\w+)?\s+set\s/i',
        'delete' => '/^delete\s+(?:(?:low_priority|quick|ignore)\s+)*from\s+(?:only\s+)?'.self::TABLE.'(?:\s+(?:as\s+)?(?!(?:where|using|order|limit|returning)\b)\w+)?(?:\s+(?:where|using|order|limit|returning)\b|\s*$)/i',
        'truncate' => '/^truncate\s+(?:table\s+)?(?:only\s+)?'.self::TABLE.'(?:\s+(?:restart|continue)\s+identity)?(?:\s+(?:restrict|cascade))?\s*$/i',
    ];

    private const string EXPLAIN_PATTERN = '/^explain\s+(?<options>\([^)]*\)\s*|(?:(?:analy[sz]e|verbose|costs|buffers|timing|summary|settings|generic_plan|memory|serialize|wal|extended|partitions|format\s*=?\s*\w+)\s+)*)(?<statement>(?:select|insert|update|delete|replace|merge|with|values|table|execute|create|declare)\b.*)$/is';

    /**
     * Classifies any SQL run outside the query builder: anything not known to be side-effect
     * free or a write to a single table changes everything.
     */
    public static function classify(string $sql, string $tablePrefix): RawStatementEffect
    {
        $statement = self::normalize($sql);

        if ($statement === '') {
            return RawStatementEffect::none();
        }

        if (self::containsMultipleStatements($statement)) {
            return RawStatementEffect::everything();
        }

        $keyword = self::firstKeyword($statement);

        if ($keyword === 'explain') {
            return self::classifyExplain($statement, $tablePrefix);
        }

        if (in_array($keyword, self::SIDE_EFFECT_FREE_KEYWORDS, true) || self::isTemporaryTableStatement($statement)) {
            return RawStatementEffect::none();
        }

        if ($keyword === 'with') {
            return preg_match(self::WRITE_KEYWORD_PATTERN, $statement) === 1
                ? RawStatementEffect::everything()
                : RawStatementEffect::none();
        }

        return self::classifyWrite($statement, $tablePrefix);
    }

    /**
     * EXPLAIN only plans a statement, except with ANALYZE, which runs it (PostgreSQL even runs
     * writes this way).
     */
    private static function classifyExplain(string $statement, string $tablePrefix): RawStatementEffect
    {
        $analyzes = static fn (string $sql): bool => preg_match('/\banaly[sz]e\b/i', $sql) === 1;

        if (preg_match(self::EXPLAIN_PATTERN, $statement, $matches) !== 1) {
            return $analyzes($statement) ? RawStatementEffect::everything() : RawStatementEffect::none();
        }

        return $analyzes($matches['options']) ? self::classify($matches['statement'], $tablePrefix) : RawStatementEffect::none();
    }

    private static function classifyWrite(string $statement, string $tablePrefix): RawStatementEffect
    {
        foreach (self::TABLE_WRITE_PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $statement, $matches) !== 1) {
                continue;
            }

            $table = $matches['table'];

            if (str_contains($table, '.')) {
                return RawStatementEffect::everything();
            }

            return RawStatementEffect::writes([TableName::withoutPrefix(trim($table, '`"[]'), $tablePrefix)], self::singleTableWrite($kind, $statement));
        }

        return RawStatementEffect::everything();
    }

    /**
     * An INSERT that names UPDATE or REPLACE anywhere outside literals may update or replace
     * existing rows (`ON DUPLICATE KEY UPDATE`, `ON CONFLICT DO UPDATE`, `INSERT OR REPLACE`);
     * REPLACE deletes the rows it conflicts with.
     */
    private static function singleTableWrite(string $kind, string $statement): Write
    {
        $code = SqlTableScanner::withoutLiterals($statement);

        return match ($kind) {
            'insert' => match (true) {
                preg_match('/\\breplace\\b/i', $code) === 1 => Write::delete(),
                preg_match('/\\bupdate\\b/i', $code) === 1 => Write::updateOfAnyColumn(),
                default => Write::insert(),
            },
            'replace', 'delete' => Write::delete(),
            'update' => Write::updateOfAnyColumn(),
            'truncate' => Write::truncate(),
            default => throw new LogicException('Unknown raw write kind '.$kind),
        };
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
