<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/postgresql.rs (Apache-2.0), commit 8a176d9.
 */
final class PostgreSqlDialect implements Dialect
{
    use DialectDefaults;

    private const string CUSTOM_OPERATOR_CHARS = '+-*/<>=~!@#%^&|`?';

    public function isDelimitedIdentifierStart(string $ch): bool
    {
        return $ch === '"';
    }

    public function isIdentifierStart(string $ch): bool
    {
        return Character::isAlphabetic($ch) || $ch === '_' || ! Character::isAscii($ch);
    }

    public function isIdentifierPart(string $ch): bool
    {
        return Character::isAlphabetic($ch)
            || Character::isAsciiDigit($ch)
            || $ch === '$'
            || $ch === '_'
            || ! Character::isAscii($ch);
    }

    public function supportsUnicodeStringLiteral(): bool
    {
        return true;
    }

    public function isCustomOperatorPart(string $ch): bool
    {
        return strlen($ch) === 1 && str_contains(self::CUSTOM_OPERATOR_CHARS, $ch);
    }

    public function supportsTildeLikeMatchOperator(): bool
    {
        return true;
    }

    public function supportsNestedComments(): bool
    {
        return true;
    }

    public function supportsCopyFromStdinData(): bool
    {
        return true;
    }

    public function supportsStringEscapeConstant(): bool
    {
        return true;
    }

    public function supportsNumericLiteralUnderscores(): bool
    {
        return true;
    }

    public function supportsNonDecimalIntegerLiterals(): bool
    {
        return true;
    }

    public function supportsGeometricTypes(): bool
    {
        return true;
    }

    private const array RESERVED_EXCLUSIONS_FOR_TABLE_ALIAS = [
        'CLUSTER', 'COLLATE', 'DAY', 'DISTRIBUTE', 'EXPLAIN', 'HOUR', 'MINUS', 'MINUTE', 'MONTH', 'SAMPLE', 'SECOND', 'SORT',
        'START', 'TOP', 'VIEW', 'YEAR',
    ];

    public function isTableAlias(Token $word, ?Token $next): bool
    {
        return ! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_ALIAS) || $word->isKeyword(...self::RESERVED_EXCLUSIONS_FOR_TABLE_ALIAS);
    }

    public function supportsLeftAssociativeJoinsWithoutParens(): bool
    {
        return false;
    }

    public function supportsTableInheritanceMarkers(): bool
    {
        return true;
    }

    public function supportsStringLiteralTableAlias(bool $explicit): bool
    {
        return false;
    }
}
