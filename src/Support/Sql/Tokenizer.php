<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

use Closure;
use LogicException;

/**
 * Ported from sqlparser-rs src/tokenizer.rs (Apache-2.0), commit 8a176d9.
 */
final class Tokenizer
{
    private const string VALID_UTF8_PREFIX = '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

    private const string COPY_DATA_TERMINATOR = '\\.';

    private readonly bool $copyFromStdinEnabled;

    private int $pos = 0;

    private int $end;

    /** @var list<Token> */
    private array $tokens = [];

    private ?Token $previousNonWhitespace = null;

    private bool $copyFirstTokenSeen = false;

    private bool $copyStartsWithCopy = false;

    private bool $copyPrevWasFrom = false;

    private bool $copyFromStdin = false;

    private int $copyParenDepth = 0;

    public function __construct(private readonly Dialect $dialect, private readonly string $sql)
    {
        $this->copyFromStdinEnabled = $dialect->supportsCopyFromStdinData();
        $this->end = strlen($sql);
    }

    /**
     * @return list<Token>
     */
    public function tokenize(): array
    {
        $this->assertValidUtf8();
        $this->pos = 0;
        $this->end = strlen($this->sql);
        $this->tokens = [];
        $this->previousNonWhitespace = null;
        $this->resetCopyStatement();

        while (($token = $this->nextToken($this->lastToken(), $this->previousNonWhitespace)) !== null) {
            [$copyData, $rest] = $token->kind === TokenKind::SemiColon && $this->copyCapturesPayload()
                ? $this->captureCopyData($token)
                : [null, []];
            $this->noteCopyStatement($token);

            if ($copyData !== null) {
                $this->emit($copyData);
            }

            if ($this->isCommentHint($token)) {
                $this->tokenizeCommentHints($token);
            } else {
                $this->emit($token);
            }

            foreach ($rest as $restToken) {
                $this->noteCopyStatement($restToken);
                $this->emit($restToken);
            }
        }

        return $this->tokens;
    }

    private function assertValidUtf8(): void
    {
        if (preg_match('//u', $this->sql) === 1) {
            return;
        }
        preg_match(self::VALID_UTF8_PREFIX, $this->sql, $validPrefix);

        throw new TokenizerException('Invalid UTF-8', strlen($validPrefix[0] ?? ''));
    }

    private function lastToken(): ?Token
    {
        return $this->tokens === [] ? null : $this->tokens[array_key_last($this->tokens)];
    }

    private function emit(Token $token): void
    {
        if (! $token->isTrivia()) {
            $this->previousNonWhitespace = $token;
        }
        $this->tokens[] = $token;
    }

    private function isCommentHint(Token $token): bool
    {
        return $token->isTrivia()
            && $this->dialect->supportsMultilineCommentHints()
            && substr_compare($this->sql, '/*', $token->offset, 2) === 0
            && str_starts_with($token->value, '!');
    }

    /**
     * Tokenizes the content of a MySQL executable comment in place, without its `!` and version digits, so its
     * tokens carry their true offsets.
     */
    private function tokenizeCommentHints(Token $comment): void
    {
        $contentStart = $comment->offset + 2;
        $hintEnd = $contentStart + strlen($comment->value);
        $hintStart = $contentStart + 1;
        $hintStart += strspn($this->sql, '0123456789', $hintStart, $hintEnd - $hintStart);
        if ($hintStart === $hintEnd) {
            return;
        }

        [$resumeAt, $outerEnd] = [$this->pos, $this->end];
        [$this->pos, $this->end] = [$hintStart, $hintEnd];
        while (($token = $this->nextToken($this->lastToken(), $this->previousNonWhitespace)) !== null) {
            $this->emit($token);
        }
        [$this->pos, $this->end] = [$resumeAt, $outerEnd];
    }

    /**
     * @return array{?Token, list<Token>}
     */
    private function captureCopyData(Token $semicolon): array
    {
        $rest = [];
        $previous = $semicolon;
        $previousNonWhitespace = $semicolon;
        while (($token = $this->nextToken($previous, $previousNonWhitespace)) !== null) {
            $rest[] = $token;
            $previous = $token;
            if (! $token->isTrivia()) {
                $previousNonWhitespace = $token;
            }
            if ($this->isNewline($token)) {
                break;
            }
        }

        $dataStart = $this->pos;
        [$data, $terminated] = $this->readCopyData();
        $payload = $data === '' && ! $terminated
            ? null
            : new Token(TokenKind::CopyData, $data, $terminated ? self::COPY_DATA_TERMINATOR : null, $dataStart);

        return [$payload, $rest];
    }

    private function isNewline(Token $token): bool
    {
        return $token->isTrivia() && $token->value === '' && str_contains("\n\r", $this->sql[$token->offset]);
    }

    /**
     * @return array{string, bool}
     */
    private function readCopyData(): array
    {
        $data = '';
        while (true) {
            $lineLength = strcspn($this->sql, "\r\n", $this->pos, $this->end - $this->pos);
            $line = substr($this->sql, $this->pos, $lineLength);
            $this->pos += $lineLength;
            if ($line === self::COPY_DATA_TERMINATOR) {
                $this->skipByte("\r");
                $this->skipByte("\n");

                return [$data, true];
            }
            $data .= $line;
            if ($this->skipByte("\n")) {
                $data .= "\n";
            } elseif ($this->skipByte("\r")) {
                $data .= "\r";
                if ($this->skipByte("\n")) {
                    $data .= "\n";
                }
            } else {
                return [$data, false];
            }
        }
    }

    /** @phpstan-impure */
    private function skipByte(string $byte): bool
    {
        if ($this->pos >= $this->end || $this->sql[$this->pos] !== $byte) {
            return false;
        }
        $this->pos++;

        return true;
    }

    private function resetCopyStatement(): void
    {
        $this->copyFirstTokenSeen = false;
        $this->copyStartsWithCopy = false;
        $this->copyPrevWasFrom = false;
        $this->copyFromStdin = false;
        $this->copyParenDepth = 0;
    }

    private function copyCapturesPayload(): bool
    {
        return $this->copyFromStdinEnabled && $this->copyStartsWithCopy && $this->copyFromStdin;
    }

    private function noteCopyStatement(Token $token): void
    {
        if (! $this->copyFromStdinEnabled) {
            return;
        }

        switch ($token->kind) {
            case TokenKind::SemiColon:
                $this->resetCopyStatement();
                break;
            case TokenKind::LParen:
                $this->copyParenDepth++;
                $this->copyFirstTokenSeen = true;
                $this->copyPrevWasFrom = false;
                break;
            case TokenKind::RParen:
                $this->copyParenDepth = max(0, $this->copyParenDepth - 1);
                $this->copyFirstTokenSeen = true;
                $this->copyPrevWasFrom = false;
                break;
            case TokenKind::Whitespace:
                break;
            case TokenKind::Word:
                if (! $this->copyFirstTokenSeen) {
                    $this->copyFirstTokenSeen = true;
                    $this->copyStartsWithCopy = $token->isKeyword('COPY');
                }
                if ($this->copyPrevWasFrom && $token->isKeyword('STDIN') && $this->copyParenDepth === 0) {
                    $this->copyFromStdin = true;
                }
                $this->copyPrevWasFrom = $token->isKeyword('FROM');
                break;
            default:
                $this->copyFirstTokenSeen = true;
                $this->copyPrevWasFrom = false;
        }
    }

    private function tokenizeIdentifierOrKeyword(string $firstChars, int $start): Token
    {
        $this->next();
        $word = $this->tokenizeWord($firstChars);

        if (strspn($word, '0123456789.') === strlen($word)) {
            $this->takeWhile(static fn (string $ch): bool => $ch === '.' || Character::isAsciiDigit($ch));

            return $this->symbol(TokenKind::Number, $start);
        }

        return $this->word($word, $start);
    }

    private function nextToken(?Token $prev, ?Token $prevNonWhitespace): ?Token
    {
        $start = $this->pos;
        $ch = $this->peek();
        if ($ch === null) {
            return null;
        }

        if ($prev?->kind === TokenKind::Period
            && Character::isAsciiAlphabetic($ch)
            && $this->dialect->supportsLiteralPrefixInQualifiedName()) {
            return $this->tokenizeIdentifierOrKeyword($ch, $start);
        }

        if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
            return $this->consumeAndReturnWhitespace($start);
        }

        if ($ch === "\r") {
            $this->next();
            if ($this->peek() === "\n") {
                $this->next();
            }

            return $this->whitespace('', $start);
        }

        if (($ch === 'B' || $ch === 'b')
            && ($this->dialect instanceof PostgreSqlDialect || $this->dialect instanceof MySqlDialect || $this->dialect instanceof GenericDialect)) {
            return $this->tokenizeByteStringOrWord($ch, $start);
        }

        if (($ch === 'R' || $ch === 'r') && $this->dialect instanceof GenericDialect) {
            return $this->tokenizeRawStringOrWord($ch, $start);
        }

        if ($ch === 'N' || $ch === 'n') {
            return $this->tokenizeNationalStringOrWord($ch, $start);
        }

        if (($ch === 'Q' || $ch === 'q') && $this->dialect->supportsQuoteDelimitedString()) {
            $this->next();
            if ($this->peek() === "'") {
                return $this->tokenizeQuoteDelimitedString(TokenKind::QuoteDelimitedStringLiteral, $ch, $start);
            }

            return $this->word($this->tokenizeWord($ch), $start);
        }

        if (($ch === 'e' || $ch === 'E') && $this->dialect->supportsStringEscapeConstant()) {
            $this->next();
            if ($this->peek() === "'") {
                return $this->literal(TokenKind::EscapedStringLiteral, $this->tokenizeEscapedSingleQuotedString($start), $start);
            }

            return $this->word($this->tokenizeWord($ch), $start);
        }

        if (($ch === 'u' || $ch === 'U') && $this->dialect->supportsUnicodeStringLiteral()) {
            $this->next();
            if ($this->peek() === '&' && $this->peekNth(1) === "'") {
                $this->next();

                return $this->literal(TokenKind::UnicodeStringLiteral, $this->unescapeUnicodeSingleQuotedString(), $start);
            }

            return $this->word($this->tokenizeWord($ch), $start);
        }

        if ($ch === 'x' || $ch === 'X') {
            $this->next();
            if ($this->peek() === "'") {
                return $this->literal(TokenKind::HexStringLiteral, $this->tokenizeSingleQuotedString("'", true), $start);
            }

            return $this->word($this->tokenizeWord($ch), $start);
        }

        if ($ch === "'") {
            return $this->tokenizeQuotedStringToken("'", TokenKind::SingleQuotedString, TokenKind::TripleSingleQuotedString, $start);
        }

        if ($ch === '"' && ! $this->dialect->isDelimitedIdentifierStart($ch) && ! $this->dialect->isIdentifierStart($ch)) {
            return $this->tokenizeQuotedStringToken('"', TokenKind::DoubleQuotedString, TokenKind::TripleDoubleQuotedString, $start);
        }

        if ($this->dialect->isDelimitedIdentifierStart($ch)) {
            return $this->word($this->tokenizeQuotedIdentifier($ch), $start, $ch);
        }

        if ($this->dialect->isNestedDelimitedIdentifierStart($ch)) {
            $quotes = $this->dialect->peekNestedDelimitedIdentifierQuotes(substr($this->sql, $this->pos, $this->end - $this->pos));
            if ($quotes !== null) {
                return $this->tokenizeNestedDelimitedIdentifier($quotes[0], $quotes[1], $start);
            }
        }

        if ($ch === '.' || Character::isAsciiDigit($ch)) {
            return $this->tokenizeNumberOrPeriod($ch, $prev, $prevNonWhitespace, $start);
        }

        return match ($ch) {
            '(' => $this->consumeAndReturn(TokenKind::LParen, $start),
            ')' => $this->consumeAndReturn(TokenKind::RParen, $start),
            ',' => $this->consumeAndReturn(TokenKind::Comma, $start),
            '-' => $this->tokenizeMinus($start),
            '/' => $this->tokenizeSlash($start),
            '+' => $this->consumeAndReturn(TokenKind::Plus, $start),
            '*' => $this->consumeAndReturn(TokenKind::Mul, $start),
            '%' => $this->tokenizePercent($start),
            '|' => $this->tokenizePipe($start),
            '=' => $this->tokenizeEquals($start),
            '!' => $this->tokenizeExclamationMark($start),
            '<' => $this->tokenizeLessThan($start),
            '>' => $this->tokenizeGreaterThan($start),
            ':' => $this->tokenizeColon($start),
            ';' => $this->consumeAndReturn(TokenKind::SemiColon, $start),
            '\\' => $this->consumeAndReturn(TokenKind::Backslash, $start),
            '[' => $this->consumeAndReturn(TokenKind::LBracket, $start),
            ']' => $this->consumeAndReturn(TokenKind::RBracket, $start),
            '&' => $this->tokenizeAmpersand($start),
            '^' => $this->tokenizeCaret($start),
            '{' => $this->consumeAndReturn(TokenKind::LBrace, $start),
            '}' => $this->consumeAndReturn(TokenKind::RBrace, $start),
            '#' => $this->tokenizeSharp($start),
            '~' => $this->tokenizeTilde($start),
            '@' => $this->tokenizeAt($start),
            '?' => $this->tokenizeQuestionMark($start),
            default => $this->tokenizeIdentifierDollarOrChar($ch, $start),
        };
    }

    private function tokenizeByteStringOrWord(string $ch, int $start): Token
    {
        $this->next();

        return match ($this->peek()) {
            "'" => $this->tokenizeQuotedStringLiteral("'", false, TokenKind::SingleQuotedByteStringLiteral, TokenKind::TripleSingleQuotedByteStringLiteral, $start),
            '"' => $this->tokenizeQuotedStringLiteral('"', false, TokenKind::DoubleQuotedByteStringLiteral, TokenKind::TripleDoubleQuotedByteStringLiteral, $start),
            default => $this->word($this->tokenizeWord($ch), $start),
        };
    }

    private function tokenizeRawStringOrWord(string $ch, int $start): Token
    {
        $this->next();

        return match ($this->peek()) {
            "'" => $this->tokenizeSingleOrTripleQuotedString("'", false, TokenKind::SingleQuotedRawStringLiteral, TokenKind::TripleSingleQuotedRawStringLiteral, $start),
            '"' => $this->tokenizeSingleOrTripleQuotedString('"', false, TokenKind::DoubleQuotedRawStringLiteral, TokenKind::TripleDoubleQuotedRawStringLiteral, $start),
            default => $this->word($this->tokenizeWord($ch), $start),
        };
    }

    private function tokenizeNationalStringOrWord(string $ch, int $start): Token
    {
        $this->next();
        $next = $this->peek();

        if ($next === "'" && $this->dialect->supportsNationalStringLiteral()) {
            $backslashEscape = $this->dialect->supportsStringLiteralBackslashEscape();

            return $this->literal(TokenKind::NationalStringLiteral, $this->tokenizeSingleQuotedString("'", $backslashEscape), $start);
        }

        if (($next === 'q' || $next === 'Q') && $this->dialect->supportsQuoteDelimitedString()) {
            $this->next();
            if ($this->peek() === "'") {
                return $this->tokenizeQuoteDelimitedString(TokenKind::NationalQuoteDelimitedStringLiteral, $ch.$next, $start);
            }

            return $this->word($this->tokenizeWord($ch.$next), $start);
        }

        return $this->word($this->tokenizeWord($ch), $start);
    }

    private function tokenizeQuotedStringToken(string $quote, TokenKind $single, TokenKind $triple, int $start): Token
    {
        return $this->tokenizeQuotedStringLiteral($quote, $this->dialect->supportsStringLiteralBackslashEscape(), $single, $triple, $start);
    }

    private function tokenizeQuotedStringLiteral(string $quote, bool $backslashEscape, TokenKind $single, TokenKind $triple, int $start): Token
    {
        if ($this->dialect->supportsTripleQuotedString()) {
            return $this->tokenizeSingleOrTripleQuotedString($quote, $backslashEscape, $single, $triple, $start);
        }

        return $this->literal($single, $this->tokenizeSingleQuotedString($quote, $backslashEscape), $start);
    }

    private function tokenizeNestedDelimitedIdentifier(string $quoteStart, ?string $nestedQuoteStart, int $start): Token
    {
        if ($nestedQuoteStart === null) {
            return $this->word($this->tokenizeQuotedIdentifier($quoteStart), $start, $quoteStart);
        }

        $quoteEnd = self::matchingEndQuote($quoteStart);
        $nestedQuoteEnd = self::matchingEndQuote($nestedQuoteStart);
        $errorAt = $this->pos;

        $this->next();
        $this->takeWhile(Character::isWhitespace(...));
        if ($this->peek() !== $nestedQuoteStart) {
            $this->fail("Expected nested delimiter '{$nestedQuoteStart}' before EOF.", $errorAt);
        }
        $word = $nestedQuoteStart.$this->tokenizeQuotedIdentifier($nestedQuoteEnd).$nestedQuoteEnd;
        $this->takeWhile(Character::isWhitespace(...));
        if ($this->peek() !== $quoteEnd) {
            $this->fail("Expected close delimiter '{$quoteEnd}' before EOF.", $errorAt);
        }
        $this->next();

        return $this->word($word, $start, $quoteStart);
    }

    private function tokenizeNumberOrPeriod(string $ch, ?Token $prev, ?Token $prevNonWhitespace, int $start): Token
    {
        if ($ch === '.' && $this->peekNth(1) === '_') {
            if ($prevNonWhitespace?->kind === TokenKind::Word) {
                return $this->consumeAndReturn(TokenKind::Period, $start);
            }

            $this->fail("Unexpected character '_'", $this->pos);
        }

        if ($ch === '.'
            && $this->dialect->supportsTupleElementAccess()
            && in_array($prev?->kind, [TokenKind::Word, TokenKind::RParen, TokenKind::RBracket, TokenKind::Number], true)) {
            return $this->consumeAndReturn(TokenKind::Period, $start);
        }

        $s = $this->tokenizeNumberPart(Character::isAsciiDigit(...));

        if ($s !== '' && $this->dialect->supportsTupleElementAccess() && $prev?->kind === TokenKind::Period) {
            return $this->symbol(TokenKind::Number, $start);
        }

        if ($s === '0' && $this->dialect->supportsNonDecimalIntegerLiterals()) {
            $radix = match ($this->peek()) {
                'x', 'X' => [16, 'a hexadecimal'],
                'o', 'O' => [8, 'an octal'],
                'b', 'B' => [2, 'a binary'],
                default => null,
            };
            if ($radix !== null) {
                return $this->tokenizeNonDecimalInteger($radix[0], $radix[1], $start);
            }
        }

        if ($s === '0' && $this->peek() === 'x') {
            $this->next();

            return $this->literal(TokenKind::HexStringLiteral, $this->tokenizeNumberPart(static fn (string $ch): bool => Character::isDigit($ch, 16)), $start);
        }

        if ($this->peek() === '.') {
            $s .= '.';
            $this->next();
        }

        if ($s === '.' && $this->dialect->supportsNumericPrefix() && $prev?->kind === TokenKind::Word) {
            return $this->symbol(TokenKind::Period, $start);
        }

        $s .= $this->tokenizeNumberPart(Character::isAsciiDigit(...));

        if ($s === '.') {
            return $this->symbol(TokenKind::Period, $start);
        }

        $exponentPart = '';
        $marker = $this->peek();
        if ($marker === 'e' || $marker === 'E') {
            $exponentPart = $marker;
            $sign = $this->peekNth(1);
            if ($sign === '+' || $sign === '-') {
                $exponentPart .= $sign;
            }
            $afterMarker = $this->peekNth(strlen($exponentPart));
            if ($afterMarker !== null
                && (Character::isAsciiDigit($afterMarker) || ($afterMarker === '_' && $this->dialect->supportsNumericLiteralUnderscores()))) {
                $this->pos += strlen($exponentPart);
                $exponentPart .= $this->tokenizeNumberPart(Character::isAsciiDigit(...));
            }
        }

        if ($this->dialect->supportsNumericPrefix()) {
            if ($exponentPart === '') {
                $word = $this->takeWhile($this->dialect->isIdentifierPart(...));
                if ($word !== '') {
                    return $this->word($this->sourceFrom($start), $start);
                }
            } elseif ($prev?->kind === TokenKind::Period) {
                return $this->word($this->sourceFrom($start), $start);
            }
        }

        if ($this->peek() === 'L') {
            $this->next();
        }

        return $this->symbol(TokenKind::Number, $start);
    }

    private function tokenizeMinus(int $start): Token
    {
        $this->next();

        switch ($this->peek()) {
            case '-':
                $isComment = true;
                if ($this->dialect->requiresSingleLineCommentWhitespace()) {
                    $afterDashes = $this->peekNth(1);
                    $isComment = $afterDashes !== null && Character::isWhitespace($afterDashes);
                }

                if ($isComment) {
                    $this->next();

                    return $this->whitespace($this->tokenizeSingleLineComment(), $start);
                }

                return $this->startBinop('-', TokenKind::Minus, $start);
            case '>':
                $this->next();

                return $this->peek() === '>'
                    ? $this->consumeForBinop('->>', TokenKind::LongArrow, $start)
                    : $this->startBinop('->', TokenKind::Arrow, $start);
            default:
                return $this->startBinop('-', TokenKind::Minus, $start);
        }
    }

    private function tokenizeSlash(int $start): Token
    {
        $this->next();
        $next = $this->peek();

        if ($next === '*') {
            $this->next();

            return $this->tokenizeMultilineComment($start);
        }

        if ($next === '/' && $this->dialect instanceof GenericDialect) {
            return $this->consumeAndReturn(TokenKind::DuckIntDiv, $start);
        }

        return $this->symbol(TokenKind::Div, $start);
    }

    private function tokenizePercent(int $start): Token
    {
        $this->next();
        $next = $this->peek();

        if ($next !== null && Character::isWhitespace($next)) {
            return $this->symbol(TokenKind::Mod, $start);
        }

        if ($next !== null && $this->dialect->isIdentifierStart('%')) {
            return $this->tokenizeIdentifierOrKeyword('%'.$next, $start);
        }

        return $this->startBinop('%', TokenKind::Mod, $start);
    }

    private function tokenizePipe(int $start): Token
    {
        $this->next();
        $next = $this->peek();

        if ($next === '/') {
            return $this->consumeForBinop('|/', TokenKind::PGSquareRoot, $start);
        }

        if ($next === '|') {
            $this->next();

            return $this->peek() === '/'
                ? $this->consumeForBinop('||/', TokenKind::PGCubeRoot, $start)
                : $this->startBinop('||', TokenKind::StringConcat, $start);
        }

        if ($next === '&' && $this->dialect->supportsGeometricTypes()) {
            $this->next();

            return $this->peek() === '>'
                ? $this->consumeForBinop('|&>', TokenKind::VerticalBarAmpersandRightAngleBracket, $start)
                : $this->startBinop('|&', null, $start);
        }

        if ($next === '>' && $this->dialect->supportsGeometricTypes()) {
            $this->next();

            return $this->peek() === '>'
                ? $this->consumeForBinop('|>>', TokenKind::VerticalBarShiftRight, $start)
                : $this->startBinop('|>', null, $start);
        }

        if ($next === '>' && $this->dialect->supportsPipeOperator()) {
            return $this->consumeForBinop('|>', TokenKind::VerticalBarRightAngleBracket, $start);
        }

        return $this->startBinop('|', TokenKind::Pipe, $start);
    }

    private function tokenizeEquals(int $start): Token
    {
        $this->next();

        return match ($this->peek()) {
            '>' => $this->consumeAndReturn(TokenKind::RArrow, $start),
            '=' => $this->consumeAndReturn(TokenKind::DoubleEq, $start),
            default => $this->symbol(TokenKind::Eq, $start),
        };
    }

    private function tokenizeExclamationMark(int $start): Token
    {
        $this->next();

        switch ($this->peek()) {
            case '=':
                return $this->consumeAndReturn(TokenKind::Neq, $start);
            case '!':
                return $this->consumeAndReturn(TokenKind::DoubleExclamationMark, $start);
            case '~':
                $this->next();
                $next = $this->peek();
                if ($next === '*') {
                    return $this->consumeAndReturn(TokenKind::ExclamationMarkTildeAsterisk, $start);
                }
                if ($next === '~' && $this->dialect->supportsTildeLikeMatchOperator()) {
                    $this->next();

                    return $this->peek() === '*'
                        ? $this->consumeAndReturn(TokenKind::ExclamationMarkDoubleTildeAsterisk, $start)
                        : $this->symbol(TokenKind::ExclamationMarkDoubleTilde, $start);
                }

                return $this->symbol(TokenKind::ExclamationMarkTilde, $start);
            default:
                return $this->symbol(TokenKind::ExclamationMark, $start);
        }
    }

    private function tokenizeLessThan(int $start): Token
    {
        $this->next();
        $next = $this->peek();
        $geometric = $this->dialect->supportsGeometricTypes();

        if ($next === '=') {
            $this->next();

            return match ($this->peek()) {
                '>' => $this->consumeForBinop('<=>', TokenKind::Spaceship, $start),
                '+', '-' => $this->symbol(TokenKind::LtEq, $start),
                default => $this->startBinop('<=', TokenKind::LtEq, $start),
            };
        }

        if ($next === '|' && $geometric) {
            return $this->consumeForBinop('<<|', TokenKind::ShiftLeftVerticalBar, $start);
        }

        if ($next === '>') {
            return $this->consumeForBinop('<>', TokenKind::Neq, $start);
        }

        if ($next === '<' && $geometric) {
            $this->next();

            return $this->peek() === '|'
                ? $this->consumeForBinop('<<|', TokenKind::ShiftLeftVerticalBar, $start)
                : $this->startBinop('<<', TokenKind::ShiftLeft, $start);
        }

        if ($next === '<') {
            return $this->consumeForBinop('<<', TokenKind::ShiftLeft, $start);
        }

        if ($next === '+') {
            return $this->symbol(TokenKind::Lt, $start);
        }

        if ($next === '-' && $geometric) {
            if ($this->peekNth(1) !== '>') {
                return $this->symbol(TokenKind::Lt, $start);
            }
            $this->next();

            return $this->consumeForBinop('<->', TokenKind::TwoWayArrow, $start);
        }

        if ($next === '^' && $geometric) {
            return $this->consumeForBinop('<^', TokenKind::LeftAngleBracketCaret, $start);
        }

        if ($next === '@') {
            return $this->consumeForBinop('<@', TokenKind::ArrowAt, $start);
        }

        return $this->startBinop('<', TokenKind::Lt, $start);
    }

    private function tokenizeGreaterThan(int $start): Token
    {
        $this->next();
        $next = $this->peek();

        return match (true) {
            $next === '=' => $this->consumeForBinop('>=', TokenKind::GtEq, $start),
            $next === '>' => $this->consumeForBinop('>>', TokenKind::ShiftRight, $start),
            $next === '^' && $this->dialect->supportsGeometricTypes() => $this->consumeForBinop('>^', TokenKind::RightAngleBracketCaret, $start),
            default => $this->startBinop('>', TokenKind::Gt, $start),
        };
    }

    private function tokenizeColon(int $start): Token
    {
        $this->next();

        return match ($this->peek()) {
            ':' => $this->consumeAndReturn(TokenKind::DoubleColon, $start),
            '=' => $this->consumeAndReturn(TokenKind::Assignment, $start),
            default => $this->symbol(TokenKind::Colon, $start),
        };
    }

    /**
     * Like upstream, `&>` consumes the character after the operator too.
     */
    private function tokenizeAmpersand(int $start): Token
    {
        $this->next();
        $next = $this->peek();
        $geometric = $this->dialect->supportsGeometricTypes();

        if ($next === '>' && $geometric) {
            $this->next();

            return $this->consumeAndReturn(TokenKind::AmpersandRightAngleBracket, $start);
        }

        if ($next === '<' && $geometric) {
            $this->next();

            return $this->peek() === '|'
                ? $this->consumeAndReturn(TokenKind::AmpersandLeftAngleBracketVerticalBar, $start)
                : $this->startBinop('&<', TokenKind::AmpersandLeftAngleBracket, $start);
        }

        if ($next === '&') {
            $this->next();

            return $this->startBinop('&&', TokenKind::Overlap, $start);
        }

        return $this->startBinop('&', TokenKind::Ampersand, $start);
    }

    private function tokenizeCaret(int $start): Token
    {
        $this->next();

        return $this->peek() === '@'
            ? $this->consumeAndReturn(TokenKind::CaretAt, $start)
            : $this->symbol(TokenKind::Caret, $start);
    }

    private function tokenizeSharp(int $start): Token
    {
        if ($this->dialect->supportsHashComment()
            && (! $this->dialect->requiresHashCommentSpace() || in_array($this->peekNth(1), [' ', '!'], true))) {
            $this->next();

            return $this->whitespace($this->tokenizeSingleLineComment(), $start);
        }

        $this->next();
        $next = $this->peek();

        if ($next === '-') {
            return $this->consumeForBinop('#-', TokenKind::HashMinus, $start);
        }

        if ($next === '>') {
            $this->next();

            return $this->peek() === '>'
                ? $this->consumeForBinop('#>>', TokenKind::HashLongArrow, $start)
                : $this->startBinop('#>', TokenKind::HashArrow, $start);
        }

        if ($next === ' ') {
            return $this->symbol(TokenKind::Sharp, $start);
        }

        if ($next === '#' && $this->dialect->supportsGeometricTypes()) {
            return $this->consumeForBinop('##', TokenKind::DoubleSharp, $start);
        }

        if ($next !== null && $this->dialect->isIdentifierStart('#')) {
            return $this->tokenizeIdentifierOrKeyword('#'.$next, $start);
        }

        return $this->startBinop('#', TokenKind::Sharp, $start);
    }

    private function tokenizeTilde(int $start): Token
    {
        $this->next();
        $next = $this->peek();

        if ($next === '*') {
            return $this->consumeForBinop('~*', TokenKind::TildeAsterisk, $start);
        }

        if ($next === '=' && $this->dialect->supportsGeometricTypes()) {
            return $this->consumeForBinop('~=', TokenKind::TildeEqual, $start);
        }

        if ($next === '~' && $this->dialect->supportsTildeLikeMatchOperator()) {
            $this->next();

            return $this->peek() === '*'
                ? $this->consumeForBinop('~~*', TokenKind::DoubleTildeAsterisk, $start)
                : $this->startBinop('~~', TokenKind::DoubleTilde, $start);
        }

        return $this->startBinop('~', TokenKind::Tilde, $start);
    }

    private function tokenizeAt(int $start): Token
    {
        $this->next();
        $next = $this->peek();
        $geometric = $this->dialect->supportsGeometricTypes();

        if ($next === '@' && $geometric) {
            return $this->consumeAndReturn(TokenKind::AtAt, $start);
        }

        if ($next === '-' && $geometric) {
            $this->next();

            return $this->peek() === '@'
                ? $this->consumeAndReturn(TokenKind::AtDashAt, $start)
                : $this->startBinop('@-', null, $start);
        }

        if ($next === '>') {
            return $this->consumeAndReturn(TokenKind::AtArrow, $start);
        }

        if ($next === '?') {
            return $this->consumeAndReturn(TokenKind::AtQuestion, $start);
        }

        if ($next === '@') {
            $this->next();
            $afterAt = $this->peek();
            if ($afterAt !== null && $afterAt !== ' ' && $this->dialect->isIdentifierStart('@')) {
                return $this->tokenizeIdentifierOrKeyword('@@'.$afterAt, $start);
            }

            return $this->symbol(TokenKind::AtAt, $start);
        }

        if ($next === null || $next === ' ' || $next === "'" || $next === '"' || $next === '`' || ! $this->dialect->isIdentifierStart('@')) {
            return $this->symbol(TokenKind::AtSign, $start);
        }

        return $this->tokenizeIdentifierOrKeyword('@'.$next, $start);
    }

    private function tokenizeQuestionMark(int $start): Token
    {
        $this->next();

        if (! $this->dialect->supportsGeometricTypes()) {
            $this->takeWhile(Character::isNumeric(...));

            return $this->symbol(TokenKind::Placeholder, $start);
        }

        switch ($this->peek()) {
            case '|':
                $this->next();

                return $this->peek() === '|'
                    ? $this->consumeAndReturn(TokenKind::QuestionMarkDoubleVerticalBar, $start)
                    : $this->symbol(TokenKind::QuestionPipe, $start);
            case '&':
                return $this->consumeAndReturn(TokenKind::QuestionAnd, $start);
            case '-':
                $this->next();

                return $this->peek() === '|'
                    ? $this->consumeAndReturn(TokenKind::QuestionMarkDashVerticalBar, $start)
                    : $this->symbol(TokenKind::QuestionMarkDash, $start);
            case '#':
                return $this->consumeAndReturn(TokenKind::QuestionMarkSharp, $start);
            default:
                return $this->symbol(TokenKind::Question, $start);
        }
    }

    private function tokenizeIdentifierDollarOrChar(string $ch, int $start): Token
    {
        if ($this->dialect->isIdentifierStart($ch)) {
            return $this->tokenizeIdentifierOrKeyword($ch, $start);
        }

        if ($ch === '$') {
            return $this->tokenizeDollarPrecededValue($start);
        }

        if (Character::isWhitespace($ch)) {
            return $this->consumeAndReturnWhitespace($start);
        }

        return $this->consumeAndReturn(TokenKind::Char, $start);
    }

    private function consumeForBinop(string $prefix, TokenKind $default, int $start): Token
    {
        $this->next();

        return $this->startBinop($prefix, $default, $start);
    }

    private function startBinop(string $prefix, ?TokenKind $default, int $start): Token
    {
        $custom = null;
        while (($ch = $this->peek()) !== null && $this->dialect->isCustomOperatorPart($ch)) {
            $custom = ($custom ?? $prefix).$ch;
            $this->next();
        }

        if ($custom !== null) {
            return new Token(TokenKind::CustomBinaryOperator, $custom, null, $start);
        }

        if ($default === null) {
            $this->fail("Expected a valid binary operator after '{$prefix}'", $this->pos);
        }

        return $this->symbol($default, $start);
    }

    private function tokenizeDollarPrecededValue(int $start): Token
    {
        $this->next();
        $supportsDollarPlaceholder = $this->dialect->supportsDollarPlaceholder();

        if ($this->peek() === '$' && ! $supportsDollarPlaceholder) {
            $this->next();

            return new Token(TokenKind::DollarQuotedString, $this->tokenizeUntaggedDollarQuotedString(), '', $start);
        }

        $tag = $this->takeWhile(static fn (string $ch): bool => Character::isAlphanumeric($ch)
            || $ch === '_'
            || ($ch === '$' && $supportsDollarPlaceholder));

        if ($this->peek() === '.'
            && $this->dialect->supportsDollarAsMoneyPrefix()
            && $tag !== ''
            && strspn($tag, '0123456789') === strlen($tag)) {
            $this->next();
            $this->takeWhile(Character::isAsciiDigit(...));

            return $this->symbol(TokenKind::Placeholder, $start);
        }

        if ($this->peek() !== '$' || $supportsDollarPlaceholder) {
            return $this->symbol(TokenKind::Placeholder, $start);
        }

        $this->next();
        $endDelimiter = '$'.$tag.'$';
        $found = strpos($this->sql, $endDelimiter, $this->pos);
        if ($found === false || $found + strlen($endDelimiter) > $this->end) {
            $this->pos = $this->end;
            $this->fail('Unterminated dollar-quoted, expected $', $this->pos);
        }
        $value = substr($this->sql, $this->pos, $found - $this->pos);
        $this->pos = $found + strlen($endDelimiter);

        return new Token(TokenKind::DollarQuotedString, $value, $tag, $start);
    }

    private function tokenizeUntaggedDollarQuotedString(): string
    {
        $s = '';
        $terminated = false;
        $prev = null;
        while (($ch = $this->peek()) !== null) {
            if ($prev === '$') {
                if ($ch === '$') {
                    $this->next();
                    $terminated = true;
                    break;
                }
                $s .= '$'.$ch;
            } elseif ($ch !== '$') {
                $s .= $ch;
            }

            $prev = $ch;
            $this->next();
        }

        if ($this->peek() === null && ! $terminated) {
            $this->fail('Unterminated dollar-quoted string', $this->pos);
        }

        return $s;
    }

    /**
     * @param  Closure(string): bool  $isDigit
     */
    private function tokenizeNumberPart(Closure $isDigit): string
    {
        $supportsSeparator = $this->dialect->supportsNumericLiteralUnderscores();
        $from = $this->pos;

        while (($ch = $this->peek()) !== null) {
            if ($isDigit($ch)) {
                $this->next();

                continue;
            }

            if (! $supportsSeparator || $ch !== '_') {
                break;
            }

            $nextChar = $this->peekNth(1);
            if ($this->pos === $from || $nextChar === null || ! $isDigit($nextChar)) {
                $this->fail("Unexpected character '_'", $this->pos);
            }
            $this->next();
        }

        return substr($this->sql, $from, $this->pos - $from);
    }

    private function tokenizeNonDecimalInteger(int $radix, string $name, int $start): Token
    {
        $prefix = '0'.$this->next();
        if ($this->peek() === '_' && $this->dialect->supportsNumericLiteralUnderscores()) {
            $this->next();
        }

        $digits = $this->tokenizeNumberPart(static fn (string $ch): bool => Character::isDigit($ch, $radix));
        if ($digits === '') {
            $this->fail("Expected {$name} digit after '{$prefix}'", $this->pos);
        }

        return $this->symbol(TokenKind::Number, $start);
    }

    private function tokenizeSingleLineComment(): string
    {
        $stops = $this->dialect instanceof PostgreSqlDialect ? "\n\r" : "\n";
        $length = strcspn($this->sql, $stops, $this->pos, $this->end - $this->pos);
        $comment = substr($this->sql, $this->pos, $length);
        $this->pos += $length;

        return $comment;
    }

    private function tokenizeWord(string $firstChars): string
    {
        return $firstChars.$this->takeWhile($this->dialect->isIdentifierPart(...));
    }

    private function tokenizeQuotedIdentifier(string $quoteStart): string
    {
        $errorAt = $this->pos;
        $this->next();
        $quoteEnd = self::matchingEndQuote($quoteStart);

        return $this->parseQuotedIdent($quoteEnd) ?? $this->fail("Expected close delimiter '{$quoteEnd}' before EOF.", $errorAt);
    }

    private function tokenizeEscapedSingleQuotedString(int $startingAt): string
    {
        return $this->unescapeSingleQuotedString() ?? $this->fail('Unterminated encoded string literal', $startingAt);
    }

    private function tokenizeSingleOrTripleQuotedString(string $quote, bool $backslashEscape, TokenKind $single, TokenKind $triple, int $start): Token
    {
        $errorAt = $this->pos;

        $openingQuotes = 0;
        while ($openingQuotes < 3 && $this->peek() === $quote) {
            $this->next();
            $openingQuotes++;
        }

        return match ($openingQuotes) {
            1 => $this->literal($single, $this->tokenizeQuotedString($quote, 1, 0, $backslashEscape), $start),
            2 => $this->literal($single, '', $start),
            3 => $this->literal($triple, $this->tokenizeQuotedString($quote, 3, 0, $backslashEscape), $start),
            default => $this->fail('invalid string literal opening', $errorAt),
        };
    }

    private function tokenizeSingleQuotedString(string $quote, bool $backslashEscape): string
    {
        return $this->tokenizeQuotedString($quote, 1, 1, $backslashEscape);
    }

    private function tokenizeQuoteDelimitedString(TokenKind $kind, string $literalPrefix, int $start): Token
    {
        $literalStart = $this->pos;
        $this->next();

        $startQuoteAt = $this->pos;
        $startQuote = $this->next();
        if ($startQuote === null || in_array($startQuote, [' ', "\t", "\r", "\n"], true)) {
            $this->fail("Invalid space, tab, newline, or EOF after '{$literalPrefix}''", $startQuoteAt);
        }
        $endQuote = match ($startQuote) {
            '[' => ']',
            '{' => '}',
            '<' => '>',
            '(' => ')',
            default => $startQuote,
        };

        $value = '';
        while (($ch = $this->next()) !== null) {
            if ($ch === $endQuote && $this->peek() === "'") {
                $this->next();

                return new Token($kind, $value, $startQuote, $start);
            }
            $value .= $ch;
        }

        $this->fail('Unterminated string literal', $literalStart);
    }

    /**
     * Quotes and backslashes are ASCII and never part of a multibyte UTF-8 sequence, so the content between them is
     * copied byte-wise.
     */
    private function tokenizeQuotedString(string $quote, int $numQuoteChars, int $openingQuotesToConsume, bool $backslashEscape): string
    {
        $errorAt = $this->pos;

        for ($i = 0; $i < $openingQuotesToConsume; $i++) {
            if ($this->next() !== $quote) {
                $this->fail('invalid string literal opening', $errorAt);
            }
        }

        $s = '';
        $consecutiveQuotes = 0;
        $stops = $backslashEscape ? $quote.'\\' : $quote;
        while ($this->pos < $this->end) {
            $ch = $this->sql[$this->pos];
            $pendingFinalQuote = $numQuoteChars === 1 || $consecutiveQuotes + 1 === $numQuoteChars;

            if ($ch === $quote && $pendingFinalQuote) {
                $this->pos++;
                if ($numQuoteChars > 1) {
                    return substr($s, 0, strlen($s) - ($numQuoteChars - 1));
                }
                if (! $this->skipByte($quote)) {
                    return $s;
                }
                $s .= $quote;

                continue;
            }

            if ($ch === '\\' && $backslashEscape) {
                $this->pos++;
                $consecutiveQuotes = 0;
                $next = $this->next();
                if ($next !== null) {
                    $s .= $this->dialect->ignoresWildcardEscapes() && ($next === '%' || $next === '_')
                        ? '\\'.$next
                        : self::unescapedCharacter($next);
                }

                continue;
            }

            $run = strcspn($this->sql, $stops, $this->pos, $this->end - $this->pos);
            if ($run === 0) {
                $this->pos++;
                $consecutiveQuotes++;
                $s .= $ch;

                continue;
            }
            $s .= substr($this->sql, $this->pos, $run);
            $this->pos += $run;
            $consecutiveQuotes = 0;
        }

        $this->fail('Unterminated string literal', $errorAt);
    }

    private static function unescapedCharacter(string $escaped): string
    {
        return match ($escaped) {
            '0' => "\0",
            'a' => "\x07",
            'b' => "\x08",
            'f' => "\x0C",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'Z' => "\x1A",
            default => $escaped,
        };
    }

    private function tokenizeMultilineComment(int $start): Token
    {
        $contentStart = $this->pos;
        $nested = 1;
        $supportsNestedComments = $this->dialect->supportsNestedComments();

        while (true) {
            $this->pos += strcspn($this->sql, '/*', $this->pos, $this->end - $this->pos);
            if ($this->pos >= $this->end) {
                if ($this->dialect->supportsUnterminatedBlockComments()) {
                    return $this->whitespace($this->sourceFrom($contentStart), $start);
                }

                $this->fail('Unexpected EOF while in a multi-line comment', $this->pos);
            }

            $ch = $this->sql[$this->pos++];
            if ($ch === '/' && $supportsNestedComments && $this->skipByte('*')) {
                $nested++;
            } elseif ($ch === '*' && $this->skipByte('/') && --$nested === 0) {
                return $this->whitespace(substr($this->sql, $contentStart, $this->pos - 2 - $contentStart), $start);
            }
        }
    }

    private function parseQuotedIdent(string $quoteEnd): ?string
    {
        $s = '';
        while ($this->pos < $this->end) {
            $run = strcspn($this->sql, $quoteEnd, $this->pos, $this->end - $this->pos);
            $s .= substr($this->sql, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->end) {
                break;
            }
            $this->pos++;
            if (! $this->skipByte($quoteEnd)) {
                return $s;
            }
            $s .= $quoteEnd;
        }

        return null;
    }

    private static function matchingEndQuote(string $quoteStart): string
    {
        return match ($quoteStart) {
            '"' => '"',
            '[' => ']',
            '`' => '`',
            default => throw new LogicException("Unexpected quoting style '{$quoteStart}'."),
        };
    }

    private function unescapeSingleQuotedString(): ?string
    {
        $this->next();
        $unescaped = '';

        while (($c = $this->next()) !== null) {
            if ($c === "'") {
                if ($this->peek() !== "'") {
                    return $unescaped;
                }
                $this->next();
                $unescaped .= "'";

                continue;
            }

            if ($c !== '\\') {
                $unescaped .= $c;

                continue;
            }

            $escaped = $this->next();
            if ($escaped === null) {
                return null;
            }
            $c = match (true) {
                $escaped === 'b' => "\x08",
                $escaped === 'f' => "\x0C",
                $escaped === 'n' => "\n",
                $escaped === 'r' => "\r",
                $escaped === 't' => "\t",
                $escaped === 'u' => $this->unescapeUnicode(4),
                $escaped === 'U' => $this->unescapeUnicode(8),
                $escaped === 'x' => $this->unescapeHex(),
                Character::isDigit($escaped, 8) => $this->unescapeOctal($escaped),
                default => $escaped,
            };

            if ($c === null || $c === "\0") {
                return null;
            }
            $unescaped .= $c;
        }

        return null;
    }

    private static function byteToChar(string $digits, int $radix): ?string
    {
        $byte = intval($digits, $radix) & 0xFF;

        return $byte <= 127 ? chr($byte) : null;
    }

    private function unescapeHex(): ?string
    {
        $digits = $this->takeDigits(16, 2);

        return $digits === '' ? 'x' : self::byteToChar($digits, 16);
    }

    private function unescapeOctal(string $first): ?string
    {
        return self::byteToChar($first.$this->takeDigits(8, 2), 8);
    }

    private function takeDigits(int $radix, int $max): string
    {
        $digits = '';
        while (strlen($digits) < $max && ($ch = $this->peek()) !== null && Character::isDigit($ch, $radix)) {
            $digits .= $ch;
            $this->next();
        }

        return $digits;
    }

    /**
     * Like Rust's `u32::from_str_radix`, the digits may carry a leading `+`.
     */
    private function unescapeUnicode(int $numChars): ?string
    {
        $digits = '';
        for ($i = 0; $i < $numChars; $i++) {
            $ch = $this->next();
            if ($ch === null) {
                return null;
            }
            $digits .= $ch;
        }

        if (preg_match('/^\+?[0-9A-Fa-f]+$/', $digits) !== 1) {
            return null;
        }

        return Character::fromCodePoint(intval(ltrim($digits, '+'), 16));
    }

    private function unescapeUnicodeSingleQuotedString(): string
    {
        $this->next();
        $unescaped = '';

        while (($c = $this->next()) !== null) {
            if ($c === "'") {
                if ($this->peek() !== "'") {
                    return $unescaped;
                }
                $this->next();
                $unescaped .= "'";

                continue;
            }

            if ($c !== '\\') {
                $unescaped .= $c;

                continue;
            }

            $next = $this->peek();
            if ($next === '\\') {
                $this->next();
                $unescaped .= '\\';
            } elseif ($next === '+') {
                $this->next();
                $unescaped .= $this->takeCharFromHexDigits(6);
            } else {
                $unescaped .= $this->takeCharFromHexDigits(4);
            }
        }

        $this->fail('Unterminated unicode encoded string literal', $this->pos);
    }

    private function takeCharFromHexDigits(int $maxDigits): string
    {
        $result = 0;
        for ($i = 0; $i < $maxDigits; $i++) {
            $next = $this->next() ?? $this->fail('Unexpected EOF while parsing hex digit in escaped unicode string.', $this->pos);
            $digit = Character::digitValue($next, 16) ?? $this->fail("Invalid hex digit in escaped unicode string: {$next}", $this->pos);
            $result = $result * 16 + $digit;
        }

        return Character::fromCodePoint($result) ?? $this->fail(sprintf('Invalid unicode character: %x', $result), $this->pos);
    }

    /** @phpstan-impure */
    private function peek(): ?string
    {
        return $this->charAt($this->pos);
    }

    /** @phpstan-impure */
    private function peekNth(int $n): ?string
    {
        $at = $this->pos;
        for ($i = 0; $i < $n; $i++) {
            $ch = $this->charAt($at);
            if ($ch === null) {
                return null;
            }
            $at += strlen($ch);
        }

        return $this->charAt($at);
    }

    /** @phpstan-impure */
    private function next(): ?string
    {
        $ch = $this->peek();
        if ($ch !== null) {
            $this->pos += strlen($ch);
        }

        return $ch;
    }

    private function charAt(int $at): ?string
    {
        if ($at >= $this->end) {
            return null;
        }
        $lead = ord($this->sql[$at]);
        if ($lead < 0x80) {
            return $this->sql[$at];
        }

        return substr($this->sql, $at, $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2));
    }

    /**
     * @param  Closure(string): bool  $predicate
     */
    private function takeWhile(Closure $predicate): string
    {
        $from = $this->pos;
        while (($ch = $this->peek()) !== null && $predicate($ch)) {
            $this->pos += strlen($ch);
        }

        return substr($this->sql, $from, $this->pos - $from);
    }

    private function sourceFrom(int $start): string
    {
        return substr($this->sql, $start, $this->pos - $start);
    }

    private function symbol(TokenKind $kind, int $start): Token
    {
        return new Token($kind, $this->sourceFrom($start), null, $start);
    }

    private function consumeAndReturn(TokenKind $kind, int $start): Token
    {
        $this->next();

        return $this->symbol($kind, $start);
    }

    private function consumeAndReturnWhitespace(int $start): Token
    {
        $this->next();

        return $this->whitespace('', $start);
    }

    private function literal(TokenKind $kind, string $value, int $start): Token
    {
        return new Token($kind, $value, null, $start);
    }

    private function word(string $value, int $start, ?string $quoteStyle = null): Token
    {
        return new Token(TokenKind::Word, $value, $quoteStyle, $start);
    }

    private function whitespace(string $text, int $start): Token
    {
        return new Token(TokenKind::Whitespace, $text, null, $start);
    }

    private function fail(string $reason, int $offset): never
    {
        throw new TokenizerException($reason, $offset);
    }
}
