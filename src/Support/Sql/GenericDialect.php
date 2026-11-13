<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/generic.rs (Apache-2.0), commit 8a176d9.
 */
final class GenericDialect implements Dialect
{
    use DialectDefaults;

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

    public function supportsUnicodeStringLiteral(): bool
    {
        return true;
    }

    public function supportsLiteralPrefixInQualifiedName(): bool
    {
        return true;
    }

    public function supportsPipeOperator(): bool
    {
        return true;
    }

    public function supportsTildeLikeMatchOperator(): bool
    {
        return true;
    }

    public function supportsNestedComments(): bool
    {
        return true;
    }

    public function supportsMultilineCommentHints(): bool
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

    public function supportsQuoteDelimitedString(): bool
    {
        return true;
    }

    public function supportsParensAroundTableFactor(): bool
    {
        return true;
    }

    public function supportsCommaJoinConstraint(): bool
    {
        return true;
    }

    public function supportsInTableExpression(): bool
    {
        return true;
    }

    public function supportsTablePartitions(): bool
    {
        return true;
    }

    public function supportsTableInheritanceMarkers(): bool
    {
        return true;
    }
}
