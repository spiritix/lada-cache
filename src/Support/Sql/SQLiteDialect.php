<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/sqlite.rs (Apache-2.0), commit 8a176d9.
 */
final class SQLiteDialect implements Dialect
{
    use DialectDefaults;

    public function isDelimitedIdentifierStart(string $ch): bool
    {
        return $ch === '`' || $ch === '"' || $ch === '[';
    }

    public function isIdentifierStart(string $ch): bool
    {
        return Character::isAsciiAlphabetic($ch) || $ch === '_' || ! Character::isAscii($ch);
    }

    public function isIdentifierPart(string $ch): bool
    {
        return $this->isIdentifierStart($ch) || Character::isAsciiDigit($ch) || $ch === '$';
    }

    public function supportsDollarPlaceholder(): bool
    {
        return true;
    }

    public function supportsNumericLiteralUnderscores(): bool
    {
        return true;
    }

    public function supportsNationalStringLiteral(): bool
    {
        return false;
    }

    public function supportsUnterminatedBlockComments(): bool
    {
        return true;
    }

    private const array RESERVED_FOR_TABLE_FACTOR_SQLITE = [
        'ADD', 'ALL', 'ALTER', 'AND', 'AS', 'AUTOINCREMENT', 'BETWEEN', 'CASE', 'CHECK', 'COLLATE', 'COMMIT', 'CONSTRAINT',
        'CREATE', 'DEFAULT', 'DEFERRABLE', 'DELETE', 'DISTINCT', 'DROP', 'ELSE', 'ESCAPE', 'EXCEPT', 'EXISTS', 'FOREIGN',
        'FROM', 'GROUP', 'HAVING', 'IN', 'INDEX', 'INSERT', 'INTERSECT', 'INTO', 'IS', 'JOIN', 'LIMIT', 'NOT', 'NOTHING',
        'NOTNULL', 'NULL', 'ON', 'OR', 'ORDER', 'PRIMARY', 'REFERENCES', 'RETURNING', 'SELECT', 'SET', 'TABLE', 'THEN', 'TO',
        'TRANSACTION', 'UNION', 'UNIQUE', 'UPDATE', 'USING', 'VALUES', 'WHEN', 'WHERE',
    ];

    public function supportsParensAroundTableFactor(): bool
    {
        return true;
    }

    public function supportsIndexedBy(): bool
    {
        return true;
    }

    public function isTableAlias(Token $word, ?Token $next): bool
    {
        return match (true) {
            $word->isKeyword('INDEXED') => false,
            $word->isKeyword('NOT') => $next?->isKeyword('INDEXED') !== true,
            default => ! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_ALIAS),
        };
    }

    public function isTableFactor(Token $word): bool
    {
        return ! $word->isKeyword(...self::RESERVED_FOR_TABLE_FACTOR_SQLITE);
    }
}
