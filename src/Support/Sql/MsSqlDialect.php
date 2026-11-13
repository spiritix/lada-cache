<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/mssql.rs (Apache-2.0), commit 8a176d9.
 */
final class MsSqlDialect implements Dialect
{
    use DialectDefaults;

    public function isDelimitedIdentifierStart(string $ch): bool
    {
        return $ch === '"' || $ch === '[';
    }

    public function isIdentifierStart(string $ch): bool
    {
        return Character::isAlphabetic($ch) || $ch === '_' || $ch === '#' || $ch === '@';
    }

    public function isIdentifierPart(string $ch): bool
    {
        return Character::isAlphabetic($ch)
            || Character::isAsciiDigit($ch)
            || $ch === '@'
            || $ch === '$'
            || $ch === '#'
            || $ch === '_';
    }

    public function supportsDollarAsMoneyPrefix(): bool
    {
        return true;
    }

    public function supportsNestedComments(): bool
    {
        return true;
    }

    private const array RESERVED_FOR_ALIAS = [
        'IF', 'ELSE', 'DECLARE', 'EXEC', 'EXECUTE', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
        'PRINT', 'WHILE', 'RETURN', 'THROW', 'RAISERROR', 'MERGE',
    ];

    public function isTableFactor(Token $word): bool
    {
        return $word->isKeyword('LIMIT') || ! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_FACTOR);
    }

    public function isTableFactorAlias(bool $explicit, Token $word, ?Token $next): bool
    {
        return ! $word->isKeyword(...self::RESERVED_FOR_ALIAS) && ($explicit || $this->isTableAlias($word, $next));
    }

    public function supportsTableVersioning(): bool
    {
        return true;
    }

    public function supportsObjectNameDoubleDotNotation(): bool
    {
        return true;
    }
}
