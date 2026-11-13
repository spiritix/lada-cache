<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/dialect/mod.rs (Apache-2.0), commit 8a176d9: the default implementations of the
 * `Dialect` trait hooks the tokenizer and the table factor parsing call.
 */
trait DialectDefaults
{
    public function isDelimitedIdentifierStart(string $ch): bool
    {
        return $ch === '"' || $ch === '`';
    }

    public function isNestedDelimitedIdentifierStart(string $ch): bool
    {
        return false;
    }

    /**
     * @return array{string, ?string}|null
     */
    public function peekNestedDelimitedIdentifierQuotes(string $chars): ?array
    {
        return null;
    }

    public function isCustomOperatorPart(string $ch): bool
    {
        return false;
    }

    public function supportsStringLiteralBackslashEscape(): bool
    {
        return false;
    }

    public function supportsNationalStringLiteral(): bool
    {
        return true;
    }

    public function supportsLiteralPrefixInQualifiedName(): bool
    {
        return false;
    }

    public function ignoresWildcardEscapes(): bool
    {
        return false;
    }

    public function supportsUnicodeStringLiteral(): bool
    {
        return false;
    }

    public function supportsNumericPrefix(): bool
    {
        return false;
    }

    public function supportsTupleElementAccess(): bool
    {
        return false;
    }

    public function supportsNumericLiteralUnderscores(): bool
    {
        return false;
    }

    public function supportsNonDecimalIntegerLiterals(): bool
    {
        return false;
    }

    public function supportsTripleQuotedString(): bool
    {
        return false;
    }

    public function supportsPipeOperator(): bool
    {
        return false;
    }

    public function supportsDollarPlaceholder(): bool
    {
        return false;
    }

    public function supportsDollarAsMoneyPrefix(): bool
    {
        return false;
    }

    public function supportsNestedComments(): bool
    {
        return false;
    }

    public function supportsHashComment(): bool
    {
        return false;
    }

    public function supportsCopyFromStdinData(): bool
    {
        return false;
    }

    public function requiresHashCommentSpace(): bool
    {
        return false;
    }

    public function supportsMultilineCommentHints(): bool
    {
        return false;
    }

    public function supportsUnterminatedBlockComments(): bool
    {
        return false;
    }

    public function supportsStringEscapeConstant(): bool
    {
        return false;
    }

    public function requiresSingleLineCommentWhitespace(): bool
    {
        return false;
    }

    public function supportsGeometricTypes(): bool
    {
        return false;
    }

    public function supportsTildeLikeMatchOperator(): bool
    {
        return false;
    }

    public function supportsQuoteDelimitedString(): bool
    {
        return false;
    }

    public function isTableFactor(Token $word): bool
    {
        return ! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_FACTOR);
    }

    public function isTableAlias(Token $word, ?Token $next): bool
    {
        return ! $word->isKeyword(...Keywords::RESERVED_FOR_TABLE_ALIAS);
    }

    public function isTableFactorAlias(bool $explicit, Token $word, ?Token $next): bool
    {
        return $explicit || $this->isTableAlias($word, $next);
    }

    public function supportsTableHints(): bool
    {
        return false;
    }

    public function supportsIndexedBy(): bool
    {
        return false;
    }

    public function supportsTablePartitions(): bool
    {
        return false;
    }

    public function supportsTableInheritanceMarkers(): bool
    {
        return false;
    }

    public function supportsParensAroundTableFactor(): bool
    {
        return false;
    }

    public function supportsLeftAssociativeJoinsWithoutParens(): bool
    {
        return true;
    }

    public function supportsCrossJoinConstraint(): bool
    {
        return false;
    }

    public function supportsCommaJoinConstraint(): bool
    {
        return false;
    }

    public function supportsTableVersioning(): bool
    {
        return false;
    }

    public function supportsObjectNameDoubleDotNotation(): bool
    {
        return false;
    }

    public function supportsStringLiteralTableAlias(bool $explicit): bool
    {
        return true;
    }

    public function supportsInTableExpression(): bool
    {
        return false;
    }
}
