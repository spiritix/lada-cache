<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/mysql.rs (Apache-2.0), commit 8a176d9. The two SQL modes
 * that change how MySQL reads quotes are options: `ANSI_QUOTES` makes `"` quote identifiers
 * instead of strings, and `NO_BACKSLASH_ESCAPES` makes a backslash an ordinary character.
 */
final readonly class MySqlDialect implements Dialect
{
    use DialectDefaults;

    public function __construct(
        private bool $ansiQuotes = false,
        private bool $backslashEscapes = true,
    ) {}

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
        return $ch === '`' || ($this->ansiQuotes && $ch === '"');
    }

    public function supportsStringLiteralBackslashEscape(): bool
    {
        return $this->backslashEscapes;
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
