<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/mysql.rs (Apache-2.0), commit 8a176d9.
 */
final class MySqlDialect implements Dialect
{
    use DialectDefaults;

    public function isIdentifierStart(string $ch): bool
    {
        return Character::isAlphabetic($ch)
            || $ch === '_'
            || $ch === '$'
            || $ch === '@'
            || ! Character::isAscii($ch);
    }

    public function isIdentifierPart(string $ch): bool
    {
        return $this->isIdentifierStart($ch) || Character::isAsciiDigit($ch);
    }

    public function isDelimitedIdentifierStart(string $ch): bool
    {
        return $ch === '`';
    }

    public function supportsStringLiteralBackslashEscape(): bool
    {
        return true;
    }

    public function ignoresWildcardEscapes(): bool
    {
        return true;
    }

    public function supportsNumericPrefix(): bool
    {
        return true;
    }

    public function supportsLiteralPrefixInQualifiedName(): bool
    {
        return true;
    }

    public function supportsMultilineCommentHints(): bool
    {
        return true;
    }

    public function requiresSingleLineCommentWhitespace(): bool
    {
        return true;
    }

    public function supportsHashComment(): bool
    {
        return true;
    }

    private const array RESERVED_FOR_TABLE_ALIAS_MYSQL = ['USE', 'IGNORE', 'FORCE', 'STRAIGHT_JOIN', 'READ', 'WRITE', 'LOW_PRIORITY', 'LOCAL'];

    public function isTableFactorAlias(bool $explicit, Token $word, ?Token $next): bool
    {
        return $explicit || (! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_ALIAS) && ! $word->isKeyword(...self::RESERVED_FOR_TABLE_ALIAS_MYSQL));
    }

    public function supportsTableHints(): bool
    {
        return true;
    }

    public function supportsCrossJoinConstraint(): bool
    {
        return true;
    }

    public function supportsTablePartitions(): bool
    {
        return true;
    }

    public function supportsLeftAssociativeJoinsWithoutParens(): bool
    {
        return false;
    }

    public function supportsStringLiteralTableAlias(bool $explicit): bool
    {
        return false;
    }
}
