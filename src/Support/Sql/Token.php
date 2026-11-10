<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/tokenizer.rs (Apache-2.0), commit 8a176d9: `Token` and `Word`, located by the byte
 * offset of their first character.
 */
final readonly class Token
{
    public function __construct(
        public TokenKind $kind,
        public string $value,
        public ?string $quoteStyle,
        public int $offset,
    ) {}

    public function isKeyword(string ...$uppercaseKeywords): bool
    {
        return $this->kind === TokenKind::Word
            && $this->quoteStyle === null
            && in_array(strtoupper($this->value), $uppercaseKeywords, true);
    }

    public function isWord(): bool
    {
        return $this->kind === TokenKind::Word;
    }

    public function is(TokenKind $kind): bool
    {
        return $this->kind === $kind;
    }

    public function isTrivia(): bool
    {
        return $this->kind === TokenKind::Whitespace;
    }
}
