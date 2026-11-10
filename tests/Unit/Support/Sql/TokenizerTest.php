<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support\Sql;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Sql\Character;
use Spiritix\LadaCache\Support\Sql\Dialect;
use Spiritix\LadaCache\Support\Sql\DialectDefaults;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\Sql\GenericDialect;
use Spiritix\LadaCache\Support\Sql\MsSqlDialect;
use Spiritix\LadaCache\Support\Sql\MySqlDialect;
use Spiritix\LadaCache\Support\Sql\PostgreSqlDialect;
use Spiritix\LadaCache\Support\Sql\SQLiteDialect;
use Spiritix\LadaCache\Support\Sql\Token;
use Spiritix\LadaCache\Support\Sql\Tokenizer;
use Spiritix\LadaCache\Support\Sql\TokenizerException;
use Spiritix\LadaCache\Support\Sql\TokenKind;

/**
 * Ported from sqlparser-rs src/tokenizer.rs and tests/sqlparser_{mysql,postgres,sqlite}.rs (Apache-2.0), commit 8a176d9.
 */
final class TokenizerTest extends TestCase
{
    /**
     * @return array<string, array{Dialect}>
     */
    public static function allDialects(): array
    {
        return [
            'generic' => [new GenericDialect],
            'postgresql' => [new PostgreSqlDialect],
            'mssql' => [new MsSqlDialect],
            'mysql' => [new MySqlDialect],
            'sqlite' => [new SQLiteDialect],
        ];
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function numericUnderscoreDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsNumericLiteralUnderscores());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function hexStringDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsNumericLiteralUnderscores() && ! $d->supportsNonDecimalIntegerLiterals());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function nestedCommentDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsNestedComments());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function flatCommentDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->supportsNestedComments());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function dialectsWithoutTildeLikeMatch(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->supportsTildeLikeMatchOperator());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function nationalStringDialectsWithoutBackslashEscape(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->supportsStringLiteralBackslashEscape() && $d->supportsNationalStringLiteral());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function backslashEscapeDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsStringLiteralBackslashEscape());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function dialectsWithoutStringEscapeConstant(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->supportsStringEscapeConstant());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function stringEscapeConstantDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsStringEscapeConstant());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function commentWhitespaceDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->requiresSingleLineCommentWhitespace());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function dialectsWithoutCommentWhitespace(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->requiresSingleLineCommentWhitespace());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function numericPrefixDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsNumericPrefix());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function geometricDialects(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => $d->supportsGeometricTypes());
    }

    /**
     * @return array<string, array{Dialect}>
     */
    public static function dialectsWithoutUnterminatedBlockComments(): array
    {
        return self::dialectsWhere(static fn (Dialect $d): bool => ! $d->supportsUnterminatedBlockComments());
    }

    public function testTokenizerExceptionCarriesItsOffset(): void
    {
        $exception = new TokenizerException('test', 0);

        $this->assertSame('test at byte offset 0', $exception->getMessage());
        $this->assertSame(0, $exception->offset);
        $this->assertNull($exception->getPrevious());
    }

    public function testTokenizeSelect1(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT 1', [self::word('SELECT'), self::space(), self::number('1')]);
    }

    public function testTokenizeSelectFloat(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT .1', [self::word('SELECT'), self::space(), self::number('.1')]);
    }

    public function testTokenizeNumericLiteralUnderscoreWithoutSupport(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT 10_000', [self::word('SELECT'), self::space(), self::number('10'), self::word('_000')]);
    }

    #[DataProvider('numericUnderscoreDialects')]
    public function testTokenizeNumericLiteralUnderscore(Dialect $dialect): void
    {
        $this->assertTokens($dialect, 'SELECT 10_000, _10_000, 1_000.123, 1_000.123_456, 1e1_0', [
            self::word('SELECT'),
            self::space(),
            self::number('10_000'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::word('_10_000'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1_000.123'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1_000.123_456'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1e1_0'),
        ]);

        foreach (['SELECT 10_00_', 'SELECT 10___0', 'SELECT 1_000.123_', 'SELECT 1._000', 'SELECT 1_a', 'SELECT 1e_1', 'SELECT 1e1_', 'SELECT 1e1__0', 'SELECT 0x1_'] as $sql) {
            $this->assertTokenizeFails($dialect, $sql, "Unexpected character '_'");
        }
    }

    #[DataProvider('hexStringDialects')]
    public function testTokenizeHexStringWithUnderscores(Dialect $dialect): void
    {
        $this->assertTokens($dialect, '0xFF_FF', [self::literal(TokenKind::HexStringLiteral, 'FF_FF')]);
        $this->assertTokenizeFails($dialect, 'SELECT 0x_1', "Unexpected character '_'");
    }

    public function testTokenizeSelectExponent(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT 1e10, 1e-10, 1e+10, 1ea, 1e-10a, 1e-10-10', [
            self::word('SELECT'),
            self::space(),
            self::number('1e10'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1e-10'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1e+10'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1'),
            self::word('ea'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1e-10'),
            self::word('a'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::number('1e-10'),
            self::symbol(TokenKind::Minus, '-'),
            self::number('10'),
        ]);
    }

    public function testTokenizeScalarFunction(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT sqrt(1)', [
            self::word('SELECT'),
            self::space(),
            self::word('sqrt'),
            self::symbol(TokenKind::LParen, '('),
            self::number('1'),
            self::symbol(TokenKind::RParen, ')'),
        ]);
    }

    public function testTokenizeStringStringConcat(): void
    {
        $this->assertTokens(new GenericDialect, "SELECT 'a' || 'b'", [
            self::word('SELECT'),
            self::space(),
            self::string('a'),
            self::space(),
            self::symbol(TokenKind::StringConcat, '||'),
            self::space(),
            self::string('b'),
        ]);
    }

    public function testTokenizeBitwiseOp(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT one | two ^ three', [
            self::word('SELECT'),
            self::space(),
            self::word('one'),
            self::space(),
            self::symbol(TokenKind::Pipe, '|'),
            self::space(),
            self::word('two'),
            self::space(),
            self::symbol(TokenKind::Caret, '^'),
            self::space(),
            self::word('three'),
        ]);
    }

    public function testTokenizeLogicalXor(): void
    {
        $expected = [self::word('SELECT'), self::space()];
        foreach ([['true', 'true'], ['false', 'false'], ['true', 'false'], ['false', 'true']] as $i => [$left, $right]) {
            if ($i > 0) {
                $expected[] = self::symbol(TokenKind::Comma, ',');
                $expected[] = self::space();
            }
            array_push($expected, self::word($left), self::space(), self::word('XOR'), self::space(), self::word($right));
        }

        $this->assertTokens(new GenericDialect, 'SELECT true XOR true, false XOR false, true XOR false, false XOR true', $expected);
    }

    public function testTokenizeSimpleSelect(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT * FROM customer WHERE id = 1 LIMIT 5', [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Mul, '*'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('customer'),
            self::space(),
            self::word('WHERE'),
            self::space(),
            self::word('id'),
            self::space(),
            self::symbol(TokenKind::Eq, '='),
            self::space(),
            self::number('1'),
            self::space(),
            self::word('LIMIT'),
            self::space(),
            self::number('5'),
        ]);
    }

    public function testTokenizeExplainSelect(): void
    {
        $this->assertTokens(new GenericDialect, 'EXPLAIN SELECT * FROM customer WHERE id = 1', [
            self::word('EXPLAIN'),
            self::space(),
            ...self::selectCustomerWhereIdIsOne(),
        ]);
    }

    public function testTokenizeExplainAnalyzeSelect(): void
    {
        $this->assertTokens(new GenericDialect, 'EXPLAIN ANALYZE SELECT * FROM customer WHERE id = 1', [
            self::word('EXPLAIN'),
            self::space(),
            self::word('ANALYZE'),
            self::space(),
            ...self::selectCustomerWhereIdIsOne(),
        ]);
    }

    public function testTokenizeStringPredicate(): void
    {
        $this->assertTokens(new GenericDialect, "SELECT * FROM customer WHERE salary != 'Not Provided'", [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Mul, '*'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('customer'),
            self::space(),
            self::word('WHERE'),
            self::space(),
            self::word('salary'),
            self::space(),
            self::symbol(TokenKind::Neq, '!='),
            self::space(),
            self::string('Not Provided'),
        ]);
    }

    public function testTokenizeInvalidString(): void
    {
        $this->assertTokens(new GenericDialect, "\n💝مصطفىh", [
            self::space(),
            self::symbol(TokenKind::Char, '💝'),
            self::word('مصطفىh'),
        ]);
    }

    public function testTokenizeNewlineInStringLiteral(): void
    {
        $this->assertTokens(new GenericDialect, "'foo\r\nbar\nbaz'", [self::string("foo\r\nbar\nbaz")]);
    }

    public function testTokenizeUnterminatedStringLiteral(): void
    {
        $this->assertTokenizeFails(new GenericDialect, "select 'foo", 'Unterminated string literal', 7);
    }

    public function testTokenizeUnterminatedStringLiteralUtf8(): void
    {
        $sql = "SELECT \"なにか\" FROM Y WHERE \"なにか\" = 'test;";

        $this->assertTokenizeFails(new GenericDialect, $sql, 'Unterminated string literal', (int) strpos($sql, "'test"));
    }

    public function testTokenizeInvalidStringCols(): void
    {
        $this->assertTokens(new GenericDialect, "\n\nSELECT * FROM table\t💝مصطفىh", [
            self::space(),
            self::space(),
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Mul, '*'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('table'),
            self::space(),
            self::symbol(TokenKind::Char, '💝'),
            self::word('مصطفىh'),
        ]);
    }

    public function testTokenizeDollarQuotedStringTagged(): void
    {
        $dialect = new GenericDialect;

        $this->assertTokens($dialect, "SELECT \$tag\$dollar '\$' quoted strings have \$tags like this\$ or like this \$\$\$tag\$", [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, "dollar '\$' quoted strings have \$tags like this\$ or like this \$\$", 'tag'),
        ]);
        $this->assertTokens($dialect, 'SELECT $abc$x$ab$abc$', [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, 'x$ab', 'abc'),
        ]);
        $this->assertTokens($dialect, 'SELECT $abc$$abc$', [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, '', 'abc'),
        ]);
        $this->assertTokens($dialect, '0$abc$$abc$1', [
            self::number('0'),
            self::literal(TokenKind::DollarQuotedString, '', 'abc'),
            self::number('1'),
        ]);
        $this->assertTokens($dialect, '$function$abc$q$data$q$$function$', [
            self::literal(TokenKind::DollarQuotedString, 'abc$q$data$q$', 'function'),
        ]);
    }

    public function testTokenizeDollarQuotedStringTaggedUnterminated(): void
    {
        $sql = "SELECT \$tag\$dollar '\$' quoted strings have \$tags like this\$ or like this \$\$\$different tag\$";

        $this->assertTokenizeFails(new GenericDialect, $sql, 'Unterminated dollar-quoted, expected $', strlen($sql));
    }

    public function testTokenizeDollarQuotedStringTaggedUnterminatedMirror(): void
    {
        $this->assertTokenizeFails(new GenericDialect, 'SELECT $abc$abc$', 'Unterminated dollar-quoted, expected $', 16);
    }

    public function testTokenizeDollarPlaceholder(): void
    {
        $this->assertTokens(new SQLiteDialect, 'SELECT $$, $$ABC$$, $ABC$, $ABC', [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Placeholder, '$$'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::symbol(TokenKind::Placeholder, '$$ABC$$'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::symbol(TokenKind::Placeholder, '$ABC$'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::symbol(TokenKind::Placeholder, '$ABC'),
        ]);
    }

    public function testTokenizeNestedDollarQuotedStrings(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT $tag$dollar $nested$ string$tag$', [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, 'dollar $nested$ string', 'tag'),
        ]);
    }

    public function testTokenizeDollarQuotedStringUntaggedEmpty(): void
    {
        $this->assertTokens(new GenericDialect, 'SELECT $$$$', [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, '', ''),
        ]);
    }

    public function testTokenizeDollarQuotedStringUntagged(): void
    {
        $this->assertTokens(new GenericDialect, "SELECT \$\$within dollar '\$' quoted strings have \$tags like this\$ \$\$", [
            self::word('SELECT'),
            self::space(),
            self::literal(TokenKind::DollarQuotedString, "within dollar '\$' quoted strings have \$tags like this\$ ", ''),
        ]);
    }

    public function testTokenizeDollarQuotedStringUntaggedUnterminated(): void
    {
        $sql = "SELECT \$\$dollar '\$' quoted strings have \$tags like this\$ or like this \$different tag\$";

        $this->assertTokenizeFails(new GenericDialect, $sql, 'Unterminated dollar-quoted string', strlen($sql));
    }

    public function testTokenizeRightArrow(): void
    {
        $this->assertTokens(new GenericDialect, 'FUNCTION(key=>value)', [
            self::word('FUNCTION'),
            self::symbol(TokenKind::LParen, '('),
            self::word('key'),
            self::symbol(TokenKind::RArrow, '=>'),
            self::word('value'),
            self::symbol(TokenKind::RParen, ')'),
        ]);
    }

    public function testTokenizeIsNull(): void
    {
        $this->assertTokens(new GenericDialect, 'a IS NULL', [self::word('a'), self::space(), self::word('IS'), self::space(), self::word('NULL')]);
    }

    public function testTokenizeComment(): void
    {
        $dialect = new GenericDialect;

        $this->assertTokens($dialect, "0--this is a comment\n1", [self::number('0'), self::comment('this is a comment'), self::space(), self::number('1')]);
        $this->assertTokens($dialect, "0--this is a comment\r1", [self::number('0'), self::comment("this is a comment\r1")]);
        $this->assertTokens($dialect, "0--this is a comment\r\n1", [self::number('0'), self::comment("this is a comment\r"), self::space(), self::number('1')]);
    }

    public function testTokenizeCommentPostgres(): void
    {
        $tokens = $this->assertTokens(new PostgreSqlDialect, "1--\r0", [self::number('1'), self::comment(''), self::space(), self::number('0')]);

        $this->assertSame([0, 1, 3, 4], self::offsets($tokens));
    }

    public function testTokenizeCommentAtEof(): void
    {
        $this->assertTokens(new GenericDialect, '--this is a comment', [self::comment('this is a comment')]);
    }

    public function testTokenizeMultilineComment(): void
    {
        $this->assertTokens(new GenericDialect, "0/*multi-line\n* /comment*/1", [self::number('0'), self::comment("multi-line\n* /comment"), self::number('1')]);
    }

    #[DataProvider('nestedCommentDialects')]
    public function testTokenizeNestedMultilineComment(Dialect $dialect): void
    {
        $this->assertTokens($dialect, "0/*multi-line\n* \n/* comment \n /*comment*/*/ */ /comment*/1", [
            self::number('0'),
            self::comment("multi-line\n* \n/* comment \n /*comment*/*/ "),
            self::space(),
            self::symbol(TokenKind::Div, '/'),
            self::word('comment'),
            self::symbol(TokenKind::Mul, '*'),
            self::symbol(TokenKind::Div, '/'),
            self::number('1'),
        ]);
        $this->assertTokens($dialect, "0/*multi-line\n* \n/* comment \n /*comment/**/ */ /comment*/*/1", [
            self::number('0'),
            self::comment("multi-line\n* \n/* comment \n /*comment/**/ */ /comment*/"),
            self::number('1'),
        ]);
        $this->assertTokens($dialect, 'SELECT 1/* a /* b */ c */0', [
            self::word('SELECT'),
            self::space(),
            self::number('1'),
            self::comment(' a /* b */ c '),
            self::number('0'),
        ]);
    }

    #[DataProvider('nestedCommentDialects')]
    public function testTokenizeNestedMultilineCommentEmpty(Dialect $dialect): void
    {
        $this->assertTokens($dialect, 'select 1/*/**/*/0', [self::word('select'), self::space(), self::number('1'), self::comment('/**/'), self::number('0')]);
    }

    #[DataProvider('flatCommentDialects')]
    public function testTokenizeNestedCommentsIfNotSupported(Dialect $dialect): void
    {
        $this->assertTokens($dialect, 'SELECT 1/*/* nested comment */*/0', [
            self::word('SELECT'),
            self::space(),
            self::number('1'),
            self::comment('/* nested comment '),
            self::symbol(TokenKind::Mul, '*'),
            self::symbol(TokenKind::Div, '/'),
            self::number('0'),
        ]);
    }

    public function testTokenizeMultilineCommentWithEvenAsterisks(): void
    {
        $this->assertTokens(new GenericDialect, "\n/** Comment **/\n", [self::space(), self::comment('* Comment *'), self::space()]);
    }

    public function testTokenizeUnicodeWhitespace(): void
    {
        $this->assertTokens(new GenericDialect, " \u{2003}\n", [self::space(), self::space(), self::space()]);
    }

    public function testTokenizeMismatchedQuotes(): void
    {
        $this->assertTokenizeFails(new GenericDialect, '"foo', "Expected close delimiter '\"' before EOF.", 0);
    }

    public function testTokenizeNewlines(): void
    {
        $tokens = $this->assertTokens(new GenericDialect, "line1\nline2\rline3\r\nline4\r", [
            self::word('line1'),
            self::space(),
            self::word('line2'),
            self::space(),
            self::word('line3'),
            self::space(),
            self::word('line4'),
            self::space(),
        ]);

        $this->assertSame([0, 5, 6, 11, 12, 17, 19, 24], self::offsets($tokens));
    }

    public function testTokenizeMssqlTop(): void
    {
        $this->assertTokens(new MsSqlDialect, 'SELECT TOP 5 [bar] FROM foo', [
            self::word('SELECT'),
            self::space(),
            self::word('TOP'),
            self::space(),
            self::number('5'),
            self::space(),
            self::word('bar', '['),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('foo'),
        ]);
    }

    public function testTokenizePgRegexMatch(): void
    {
        $this->assertTokens(new GenericDialect, "SELECT col ~ '^a', col ~* '^a', col !~ '^a', col !~* '^a'", self::columnComparisons('^a', [
            self::symbol(TokenKind::Tilde, '~'),
            self::symbol(TokenKind::TildeAsterisk, '~*'),
            self::symbol(TokenKind::ExclamationMarkTilde, '!~'),
            self::symbol(TokenKind::ExclamationMarkTildeAsterisk, '!~*'),
        ]));
    }

    public function testTokenizePgLikeMatch(): void
    {
        $this->assertTokens(new GenericDialect, "SELECT col ~~ '_a%', col ~~* '_a%', col !~~ '_a%', col !~~* '_a%'", self::columnComparisons('_a%', [
            self::symbol(TokenKind::DoubleTilde, '~~'),
            self::symbol(TokenKind::DoubleTildeAsterisk, '~~*'),
            self::symbol(TokenKind::ExclamationMarkDoubleTilde, '!~~'),
            self::symbol(TokenKind::ExclamationMarkDoubleTildeAsterisk, '!~~*'),
        ]));
    }

    #[DataProvider('dialectsWithoutTildeLikeMatch')]
    public function testTokenizeTildeWithoutLikeMatchOperators(Dialect $dialect): void
    {
        $this->assertTokens($dialect, '~~ ~~* !~~ !~~*', [
            self::symbol(TokenKind::Tilde, '~'),
            self::symbol(TokenKind::Tilde, '~'),
            self::space(),
            self::symbol(TokenKind::Tilde, '~'),
            self::symbol(TokenKind::TildeAsterisk, '~*'),
            self::space(),
            self::symbol(TokenKind::ExclamationMarkTilde, '!~'),
            self::symbol(TokenKind::Tilde, '~'),
            self::space(),
            self::symbol(TokenKind::ExclamationMarkTilde, '!~'),
            self::symbol(TokenKind::TildeAsterisk, '~*'),
        ]);
    }

    public function testTokenizeQuotedIdentifier(): void
    {
        $this->assertTokens(new GenericDialect, ' "a "" b" "a """ "c """"" ', [
            self::space(),
            self::word('a " b', '"'),
            self::space(),
            self::word('a "', '"'),
            self::space(),
            self::word('c ""', '"'),
            self::space(),
        ]);
    }

    public function testTokenizeWithLocation(): void
    {
        $tokens = $this->assertTokens(new GenericDialect, "SELECT a,\n b", [
            self::word('SELECT'),
            self::space(),
            self::word('a'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::space(),
            self::word('b'),
        ]);

        $this->assertSame([0, 6, 7, 8, 9, 10, 11], self::offsets($tokens));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function escapeSequences(): iterable
    {
        yield 'backspace' => ['\b', "\u{0008}"];
        yield 'form feed' => ['\f', "\u{000C}"];
        yield 'tab' => ['\t', "\t"];
        yield 'carriage return and newline' => ['\r\n', "\r\n"];
        yield 'escaped slash' => ['\/', '/'];
        yield 'slash' => ['/', '/'];
        yield 'backslash' => ['\\\\', '\\'];
        yield '16-bit unicode' => ['\u0001', "\u{0001}"];
        yield '16-bit unicode letter' => ['䲑', "\u{4c91}"];
        yield '16-bit unicode followed by a digit' => ['䲑6', "\u{4c91}6"];
        yield 'short 16-bit unicode' => ['\u4c', null];
        yield '16-bit unicode null' => ['\u0000', null];
        yield '32-bit unicode' => ['\U0010FFFF', "\u{10FFFF}"];
        yield '32-bit unicode beyond the range' => ['\U00110000', null];
        yield '32-bit unicode null' => ['\U00000000', null];
        yield 'bare u' => ['\u', null];
        yield 'bare U' => ['\U', null];
        yield '32-bit unicode far beyond the range' => ['\U1010FFFF', null];
        yield 'hex byte' => ['\x4B', "\u{004b}"];
        yield 'short hex byte' => ['\x4', "\u{0004}"];
        yield 'short hex byte followed by a letter' => ['\x4L', "\u{0004}L"];
        yield 'bare x' => ['\x', 'x'];
        yield 'bare x followed by a letter' => ['\xP', 'xP'];
        yield 'hex null' => ['\x0', null];
        yield 'hex byte above 127 followed by a digit' => ['\xCAD', null];
        yield 'hex byte above 127' => ['\xA9', null];
        yield 'octal byte' => ['\1', "\u{0001}"];
        yield 'two-digit octal byte' => ['\12', "\u{000a}"];
        yield 'three-digit octal byte' => ['\123', "\u{0053}"];
        yield 'three-digit octal byte followed by a digit' => ['\1232', "\u{0053}2"];
        yield 'octal four' => ['\4', "\u{0004}"];
        yield 'two-digit octal from four' => ['\45', "\u{0025}"];
        yield 'octal byte wrapping past 255' => ['\450', "\u{0028}"];
        yield 'octal byte above 127' => ['\603', null];
        yield 'octal null' => ['\0', null];
        yield 'octal null followed by a non-octal digit' => ['\080', null];
        yield 'non-octal digit' => ['\9', '9'];
        yield 'doubled quote' => ["''", "'"];
        yield 'mixed' => ['Hello\r\nRust/䲑 SQL Parser\U0010ABCD\1232', "Hello\r\nRust/\u{4c91} SQL Parser\u{10abcd}\u{0053}2"];
        yield 'trailing null' => ['Hello\0', null];
        yield 'hex byte above 127 inside text' => ['Hello\xCADRust', null];
    }

    #[DataProvider('escapeSequences')]
    public function testUnescape(string $escaped, ?string $expected): void
    {
        $sql = "E'{$escaped}'";

        if ($expected === null) {
            $this->assertTokenizeFails(new GenericDialect, $sql, 'Unterminated encoded string literal', 0);

            return;
        }

        $this->assertTokens(new GenericDialect, $sql, [self::literal(TokenKind::EscapedStringLiteral, $expected)]);
    }

    public function testTokenizeNumericPrefixTrait(): void
    {
        $numericPrefixDialect = new class implements Dialect
        {
            use DialectDefaults;

            public function isIdentifierStart(string $ch): bool
            {
                return Character::isAsciiAlphabetic($ch) || Character::isAsciiDigit($ch) || $ch === '$';
            }

            public function isIdentifierPart(string $ch): bool
            {
                return Character::isAsciiAlphabetic($ch) || Character::isAsciiDigit($ch) || in_array($ch, ['_', '$', '{', '}'], true);
            }

            public function supportsNumericPrefix(): bool
            {
                return true;
            }
        };

        foreach ([$numericPrefixDialect, new MySqlDialect] as $dialect) {
            $this->assertTokens($dialect, 'SELECT * FROM 1', [
                self::word('SELECT'),
                self::space(),
                self::symbol(TokenKind::Mul, '*'),
                self::space(),
                self::word('FROM'),
                self::space(),
                self::number('1'),
            ]);
        }
    }

    public function testTokenizeQuotedStringEscape(): void
    {
        $mysql = new MySqlDialect;
        foreach ([
            ["'%a\\'%b'", "%a'%b"],
            ["'a\\'\\'b\\'c\\'d'", "a''b'c'd"],
            ["'\\\\'", '\\'],
            ["'\\0\\a\\b\\f\\n\\r\\t\\Z'", "\0\u{7}\u{8}\u{c}\n\r\t\u{1a}"],
            ["'\\\"'", '"'],
            ["'\\\\a\\\\b\\'c'", "\\a\\b'c"],
            ["'\\'abcd'", "'abcd"],
            ["'''a''b'", "'a'b"],
            ["'\\q'", 'q'],
            ["'\\\\%\\\\_'", '\\%\\_'],
        ] as [$sql, $expected]) {
            $this->assertTokens($mysql, $sql, [self::string($expected)]);
        }

        foreach (["'\\'", "'ab\\'"] as $sql) {
            $this->assertTokenizeFails($mysql, $sql, 'Unterminated string literal');
        }

        foreach ([["'\\'", '\\'], ["'ab\\'", 'ab\\']] as [$sql, $expected]) {
            $this->assertTokens(new GenericDialect, $sql, [self::string($expected)]);
        }

        foreach ([["'\\%'", '\\%'], ["'\\_'", '\\_']] as [$sql, $expected]) {
            $this->assertTokens($mysql, $sql, [self::string($expected)]);
        }
    }

    /**
     * @return array<string, array{string, string, TokenKind}>
     */
    public static function tripleQuotes(): array
    {
        return [
            'double' => ['"', "'", TokenKind::TripleDoubleQuotedString],
            'single' => ["'", '"', TokenKind::TripleSingleQuotedString],
        ];
    }

    #[DataProvider('tripleQuotes')]
    public function testTokenizeTripleQuotedString(string $q, string $r, TokenKind $kind): void
    {
        $dialect = self::tripleQuotedStringDialect();

        foreach ([
            ["{$q}{$q}{$q}{$q}{$q}{$q}", ''],
            ["{$q}{$q}{$q}ab{$q}{$q}\\{$q}{$q}cd{$q}{$q}{$q}", "ab{$q}{$q}{$q}{$q}cd"],
            ["{$q}{$q}{$q}abc{$q}{$q}{$q}", 'abc'],
            ["{$q}{$q}{$q}ab{$r}{$r}{$r}c{$r}def{$r}{$r}{$r}{$q}{$q}{$q}", "ab{$r}{$r}{$r}c{$r}def{$r}{$r}{$r}"],
            ["{$q}{$q}{$q}ab{$q}{$q}c{$q}{$q}\\{$q}de{$q}{$q}f{$q}{$q}{$q}", "ab{$q}{$q}c{$q}{$q}{$q}de{$q}{$q}f"],
            ["{$q}{$q}{$q}a\\'\\'b\\'c\\'d{$q}{$q}{$q}", "a''b'c'd"],
            ["{$q}{$q}{$q}abc\\0\\n\\rdef{$q}{$q}{$q}", "abc\0\n\rdef"],
        ] as [$sql, $expected]) {
            $this->assertTokens($dialect, $sql, [self::literal($kind, $expected)]);
        }

        foreach ([
            "{$q}{$q}{$q}{$q}{$q}\\{$q}",
            "{$q}{$q}{$q}abc{$q}{$q}\\{$q}",
            "{$q}{$q}{$q}{$q}",
            "{$q}{$q}{$q}{$r}{$r}",
            "{$q}{$q}{$q}abc{$q}",
            "{$q}{$q}{$q}abc{$q}{$q}",
            "{$q}{$q}{$q}abc",
        ] as $sql) {
            $this->assertTokenizeFails($dialect, $sql, 'Unterminated string literal');
        }
    }

    public function testTokenizeEmptyQuotedStringsNextToEachOther(): void
    {
        $dialect = self::tripleQuotedStringDialect();

        $this->assertTokens($dialect, "\"\"''", [self::literal(TokenKind::DoubleQuotedString, ''), self::string('')]);
        $this->assertTokens($dialect, "''\"\"", [self::string(''), self::literal(TokenKind::DoubleQuotedString, '')]);
        $this->assertTokens(new GenericDialect, "''''''", [self::string("''")]);
    }

    public function testMysqlUsersGrantees(): void
    {
        $this->assertTokens(new MySqlDialect, 'CREATE USER `root`@`%`', [
            self::word('CREATE'),
            self::space(),
            self::word('USER'),
            self::space(),
            self::word('root', '`'),
            self::symbol(TokenKind::AtSign, '@'),
            self::word('%', '`'),
        ]);
    }

    public function testPostgresAbsWithoutSpaceAndStringLiteral(): void
    {
        $this->assertTokens(new MySqlDialect, "SELECT @'1'", [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::AtSign, '@'),
            self::string('1'),
        ]);
    }

    public function testPostgresAbsWithoutSpaceAndQuotedColumn(): void
    {
        $this->assertTokens(new MySqlDialect, 'SELECT @"bar" FROM foo', [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::AtSign, '@'),
            self::literal(TokenKind::DoubleQuotedString, 'bar'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('foo'),
        ]);
    }

    #[DataProvider('nationalStringDialectsWithoutBackslashEscape')]
    public function testNationalStringsBackslashEscapeNotSupported(Dialect $dialect): void
    {
        $this->assertTokens($dialect, "select n'''''\\'", [
            self::word('select'),
            self::space(),
            self::literal(TokenKind::NationalStringLiteral, "''\\"),
        ]);
    }

    #[DataProvider('backslashEscapeDialects')]
    public function testNationalStringsBackslashEscapeSupported(Dialect $dialect): void
    {
        $this->assertTokens($dialect, "select n'''''\\''", [
            self::word('select'),
            self::space(),
            self::literal(TokenKind::NationalStringLiteral, "'''"),
        ]);
    }

    #[DataProvider('dialectsWithoutStringEscapeConstant')]
    public function testStringEscapeConstantNotSupported(Dialect $dialect): void
    {
        foreach (['e', 'E'] as $prefix) {
            $this->assertTokens($dialect, "select {$prefix}'...'", [
                self::word('select'),
                self::space(),
                self::word($prefix),
                self::string('...'),
            ]);
        }
    }

    #[DataProvider('stringEscapeConstantDialects')]
    public function testStringEscapeConstantSupported(Dialect $dialect): void
    {
        foreach (['e', 'E'] as $prefix) {
            $this->assertTokens($dialect, "select {$prefix}'\\''", [
                self::word('select'),
                self::space(),
                self::literal(TokenKind::EscapedStringLiteral, "'"),
            ]);
        }
    }

    #[DataProvider('commentWhitespaceDialects')]
    public function testWhitespaceRequiredAfterSingleLineComment(Dialect $dialect): void
    {
        $this->assertTokens($dialect, "SELECT --'abc'", [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Minus, '-'),
            self::symbol(TokenKind::Minus, '-'),
            self::string('abc'),
        ]);
        $this->assertTokens($dialect, "SELECT -- 'abc'", [self::word('SELECT'), self::space(), self::comment(" 'abc'")]);
        $this->assertTokens($dialect, 'SELECT --', [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Minus, '-'),
            self::symbol(TokenKind::Minus, '-'),
        ]);
        $this->assertTokens($dialect, "--\n-- Table structure for table...\n--\n", [
            self::comment(''),
            self::space(),
            self::comment(' Table structure for table...'),
            self::space(),
            self::comment(''),
            self::space(),
        ]);
    }

    #[DataProvider('dialectsWithoutCommentWhitespace')]
    public function testWhitespaceNotRequiredAfterSingleLineComment(Dialect $dialect): void
    {
        $this->assertTokens($dialect, "SELECT --'abc'", [self::word('SELECT'), self::space(), self::comment("'abc'")]);
        $this->assertTokens($dialect, "SELECT -- 'abc'", [self::word('SELECT'), self::space(), self::comment(" 'abc'")]);
        $this->assertTokens($dialect, 'SELECT --', [self::word('SELECT'), self::space(), self::comment('')]);
    }

    #[DataProvider('numericPrefixDialects')]
    public function testTokenizeIdentifiersNumericPrefix(Dialect $dialect): void
    {
        $this->assertTokens($dialect, '123abc', [self::word('123abc')]);
        $this->assertTokens($dialect, '12e34', [self::number('12e34')]);
        $this->assertTokens($dialect, 't.12e34', [self::word('t'), self::symbol(TokenKind::Period, '.'), self::word('12e34')]);
        $this->assertTokens($dialect, 't.1two3', [self::word('t'), self::symbol(TokenKind::Period, '.'), self::word('1two3')]);
    }

    public function testTokenizePeriodUnderscore(): void
    {
        $dialect = new PostgreSqlDialect;

        $this->assertTokens($dialect, 'SELECT table._col', [
            self::word('SELECT'),
            self::space(),
            self::word('table'),
            self::symbol(TokenKind::Period, '.'),
            self::word('_col'),
        ]);
        $this->assertTokens($dialect, 'SELECT table ._col', [
            self::word('SELECT'),
            self::space(),
            self::word('table'),
            self::space(),
            self::symbol(TokenKind::Period, '.'),
            self::word('_col'),
        ]);
        $this->assertTokenizeFails($dialect, '._abc', "Unexpected character '_'", 0);
    }

    public function testTokenizeQuestionMark(): void
    {
        $this->assertTokens(new PostgreSqlDialect, 'SELECT x ? y', [
            self::word('SELECT'),
            self::space(),
            self::word('x'),
            self::space(),
            self::symbol(TokenKind::Question, '?'),
            self::space(),
            self::word('y'),
        ]);
    }

    public function testTokenizeMultilineCommentWithCommentHint(): void
    {
        $tokens = $this->assertTokens(new MySqlDialect, '0/*! word */1', [
            self::number('0'),
            self::space(),
            self::word('word'),
            self::space(),
            self::number('1'),
        ]);

        $this->assertSame([0, 4, 5, 9, 12], self::offsets($tokens));
    }

    public function testTokenizeMultilineCommentWithCommentHintAndVersion(): void
    {
        $dialect = new MySqlDialect;

        $tokens = $this->assertTokens($dialect, '0 /*!50110 KEY_BLOCK_SIZE = 1024*/ 1', [
            self::number('0'),
            self::space(),
            self::space(),
            self::word('KEY_BLOCK_SIZE'),
            self::space(),
            self::symbol(TokenKind::Eq, '='),
            self::space(),
            self::number('1024'),
            self::space(),
            self::number('1'),
        ]);
        $this->assertSame([0, 1, 10, 11, 25, 26, 27, 28, 34, 35], self::offsets($tokens));

        $this->assertTokens($dialect, '0 /*!50110 */ 1', [self::number('0'), self::space(), self::space(), self::space(), self::number('1')]);
        $this->assertTokens($dialect, '0 /*!*/ 1', [self::number('0'), self::space(), self::space(), self::number('1')]);
        $this->assertTokens($dialect, '0 /*!   */ 1', [
            self::number('0'),
            self::space(),
            self::space(),
            self::space(),
            self::space(),
            self::space(),
            self::number('1'),
        ]);
    }

    public function testTokenizeCommentHintUnderscoreAfterNonWordIsError(): void
    {
        $sql = 'SELECT 1 /*!50110 ._a */ FROM t';

        $this->assertTokenizeFails(new MySqlDialect, $sql, "Unexpected character '_'", (int) strpos($sql, '._a'));
    }

    #[DataProvider('allDialects')]
    public function testTokenizeLt(Dialect $dialect): void
    {
        $selectA = [self::word('select'), self::space(), self::word('a'), self::space()];

        $this->assertTokens($dialect, 'select a <-50', [...$selectA, self::symbol(TokenKind::Lt, '<'), self::symbol(TokenKind::Minus, '-'), self::number('50')]);
        $this->assertTokens($dialect, 'select a <+50', [...$selectA, self::symbol(TokenKind::Lt, '<'), self::symbol(TokenKind::Plus, '+'), self::number('50')]);
        $this->assertTokens($dialect, 'select a <=-50', [...$selectA, self::symbol(TokenKind::LtEq, '<='), self::symbol(TokenKind::Minus, '-'), self::number('50')]);
        $this->assertTokens($dialect, 'select a <=+50', [...$selectA, self::symbol(TokenKind::LtEq, '<='), self::symbol(TokenKind::Plus, '+'), self::number('50')]);
        $this->assertTokens($dialect, 'select a <-b', [...$selectA, self::symbol(TokenKind::Lt, '<'), self::symbol(TokenKind::Minus, '-'), self::word('b')]);
        $this->assertTokens($dialect, 'select a <+b', [...$selectA, self::symbol(TokenKind::Lt, '<'), self::symbol(TokenKind::Plus, '+'), self::word('b')]);
    }

    #[DataProvider('geometricDialects')]
    public function testTokenizeTwoWayArrow(Dialect $dialect): void
    {
        $this->assertTokens($dialect, 'select a <->b', [
            self::word('select'),
            self::space(),
            self::word('a'),
            self::space(),
            self::symbol(TokenKind::TwoWayArrow, '<->'),
            self::word('b'),
        ]);
    }

    public function testTokenizePeriodBeforeDigitsWithoutTupleElementAccess(): void
    {
        $this->assertTokens(new GenericDialect, 't.1', [self::word('t'), self::number('.1')]);
    }

    public function testTokenizeCopyFromStdinPayload(): void
    {
        $this->assertTokens(new PostgreSqlDialect, "COPY t FROM STDIN; SELECT 1;\nrow1\n\\.\nSELECT 2;", [
            self::word('COPY'),
            self::space(),
            self::word('t'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('STDIN'),
            self::literal(TokenKind::CopyData, "row1\n", '\\.'),
            self::symbol(TokenKind::SemiColon, ';'),
            self::space(),
            self::word('SELECT'),
            self::space(),
            self::number('1'),
            self::symbol(TokenKind::SemiColon, ';'),
            self::space(),
            self::word('SELECT'),
            self::space(),
            self::number('2'),
            self::symbol(TokenKind::SemiColon, ';'),
        ]);
    }

    public function testTokenizeQualifiedUnderscoreAcrossCopyPayload(): void
    {
        foreach (["COPY t FROM STDIN; SELECT t ._a FROM t;\nrow1\n\\.\n", "COPY t FROM STDIN; SELECT t\nrow1\n\\.\n ._a FROM t;"] as $sql) {
            $tokens = array_values(array_filter((new Tokenizer(new PostgreSqlDialect, $sql))->tokenize(), static fn (Token $token): bool => ! $token->isTrivia()));

            $this->assertSame([
                self::word('COPY'),
                self::word('t'),
                self::word('FROM'),
                self::word('STDIN'),
                self::literal(TokenKind::CopyData, "row1\n", '\\.'),
                self::symbol(TokenKind::SemiColon, ';'),
                self::word('SELECT'),
                self::word('t'),
                self::symbol(TokenKind::Period, '.'),
                self::word('_a'),
                self::word('FROM'),
                self::word('t'),
                self::symbol(TokenKind::SemiColon, ';'),
            ], self::describe($tokens), $sql);
        }
    }

    public function testTokenizeCopyFromStdinPayloadOffset(): void
    {
        $tokens = (new Tokenizer(new PostgreSqlDialect, "COPY t FROM STDIN;\nrow1\n\\."))->tokenize();
        $payloads = array_values(array_filter($tokens, static fn (Token $token): bool => $token->is(TokenKind::CopyData)));

        $this->assertCount(1, $payloads);
        $this->assertSame([TokenKind::CopyData, "row1\n", '\\.'], self::describe($payloads)[0]);
        $this->assertSame(19, $payloads[0]->offset);
    }

    public function testTokenizeCopyFromStdinPayloadMalformedSemicolonLine(): void
    {
        $this->assertTokenizeFails(new PostgreSqlDialect, "COPY t FROM STDIN; 'unterminated", 'Unterminated string literal', 19);
    }

    public function testTokenizeCopyFromStdinPayloadNotCaptured(): void
    {
        foreach (["COPY (SELECT x FROM stdin) TO STDOUT;\nrow1\n\\.\n", "SELECT * FROM stdin;\nrow1\n\\.\n", "COPY t TO STDOUT;\nrow1\n\\.\n"] as $sql) {
            $this->assertNoCopyData(new PostgreSqlDialect, $sql);
        }
    }

    public function testParseCopyFromStdinPayloadDialectGating(): void
    {
        $this->assertNoCopyData(new MySqlDialect, "COPY t FROM STDIN;\nrow1\n\\.\n");
    }

    public function testParseCopyFromStdinPayloadCommentOnSemicolonLine(): void
    {
        $tokens = (new Tokenizer(new PostgreSqlDialect, "COPY t FROM STDIN; -- note\nO'Brien\n\\.\n"))->tokenize();

        $this->assertSame([
            self::word('COPY'),
            self::space(),
            self::word('t'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('STDIN'),
            self::literal(TokenKind::CopyData, "O'Brien\n", '\\.'),
            self::symbol(TokenKind::SemiColon, ';'),
            self::space(),
            self::comment(' note'),
            self::space(),
        ], self::describe($tokens));
    }

    public function testParseUnterminatedBlockComment(): void
    {
        $dialect = new SQLiteDialect;

        $this->assertTokens($dialect, 'SELECT 1 /* x', [self::word('SELECT'), self::space(), self::number('1'), self::space(), self::comment(' x')]);
        $this->assertTokens($dialect, 'SELECT 1 /* x;', [self::word('SELECT'), self::space(), self::number('1'), self::space(), self::comment(' x;')]);
        $this->assertTokens($dialect, '/* ;', [self::comment(' ;')]);
    }

    #[DataProvider('dialectsWithoutUnterminatedBlockComments')]
    public function testParseUnterminatedBlockCommentWithoutSupport(Dialect $dialect): void
    {
        $this->assertTokenizeFails($dialect, 'SELECT 1 /* x', 'Unexpected EOF while in a multi-line comment', 13);
    }

    public function testParseUnterminatedEscape(): void
    {
        $this->assertTokenizeFails(new MySqlDialect, "SELECT 'I\\'m not fine\\'", 'Unterminated string literal', 7);
        $this->assertTokenizeFails(new MySqlDialect, "SELECT 'I\\\\'m not fine'", 'Unterminated string literal', 22);
    }

    public function testParseIncorrectDollarQuotedString(): void
    {
        $this->assertTokenizeFails(new PostgreSqlDialect, 'SELECT $x$hello$$', 'Unterminated dollar-quoted, expected $', 17);
        $this->assertTokenizeFails(new PostgreSqlDialect, 'SELECT $hello$$', 'Unterminated dollar-quoted, expected $', 15);
        $this->assertTokenizeFails(new PostgreSqlDialect, 'SELECT $$$', 'Unterminated dollar-quoted string', 10);
    }

    public function testParsePgAbsSpaceBeforeNegativeOperand(): void
    {
        $this->assertTokenizeFails(new PostgreSqlDialect, 'SELECT @-2', "Expected a valid binary operator after '@-'", 9);
    }

    /**
     * @return array<string, array{string, TokenKind}>
     */
    public static function postgresBinaryOperators(): array
    {
        $operators = [
            '#' => ['#', TokenKind::Sharp],
            '^' => ['^', TokenKind::Caret],
            '>>' => ['>>', TokenKind::ShiftRight],
            '<<' => ['<<', TokenKind::ShiftLeft],
            '&&' => ['&&', TokenKind::Overlap],
            '^@' => ['^@', TokenKind::CaretAt],
        ];
        foreach (['&&&', '|=|', '<<#>>', '&@', '&@~', '&@*', '&`', '&@|', '&@~|', '<<%', '%>>', '<<<->', '#=', '-|-', '~++', '~##', '~-~', '~!!', '~@~', '~??', '~%%', '~@@', '~==', '~^^', '~||', '~#~', '~**', '~~~', '~=~', '~!~', '~*~', '>@@<', '<@@>'] as $custom) {
            $operators[$custom] = [$custom, TokenKind::CustomBinaryOperator];
        }

        return $operators;
    }

    #[DataProvider('postgresBinaryOperators')]
    public function testParsePgBinaryOps(string $operator, TokenKind $kind): void
    {
        $this->assertTokens(new PostgreSqlDialect, "a{$operator}b", [self::word('a'), self::symbol($kind, $operator), self::word('b')]);
    }

    /**
     * @return array<string, array{string, Dialect}>
     */
    public static function laravelDrivers(): array
    {
        return [
            'mysql' => ['mysql', new MySqlDialect],
            'mariadb' => ['mariadb', new MySqlDialect],
            'pgsql' => ['pgsql', new PostgreSqlDialect],
            'sqlite' => ['sqlite', new SQLiteDialect],
            'sqlsrv' => ['sqlsrv', new MsSqlDialect],
        ];
    }

    #[DataProvider('laravelDrivers')]
    public function testDialectForLaravelDriver(string $driver, Dialect $expected): void
    {
        $this->assertInstanceOf($expected::class, Dialects::forDriver($driver));
    }

    public function testUnknownDriverHasNoDialect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'oracle'");

        Dialects::forDriver('oracle');
    }

    public function testOnlyUnquotedWordsAreKeywords(): void
    {
        [$select, , $quoted] = (new Tokenizer(new PostgreSqlDialect, 'select "from"'))->tokenize();

        $this->assertTrue($select->isKeyword('INSERT', 'SELECT'));
        $this->assertFalse($select->isKeyword('INSERT'));
        $this->assertTrue($quoted->isWord());
        $this->assertFalse($quoted->isKeyword('FROM'));
    }

    public function testOffsetsCountBytesOfMultibyteCharacters(): void
    {
        $tokens = $this->assertTokens(new PostgreSqlDialect, "SELECT ÄÖ, 'ü' FROM t", [
            self::word('SELECT'),
            self::space(),
            self::word('ÄÖ'),
            self::symbol(TokenKind::Comma, ','),
            self::space(),
            self::string('ü'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('t'),
        ]);

        $this->assertSame([0, 6, 7, 11, 12, 13, 17, 18, 22, 23], self::offsets($tokens));
    }

    public function testInvalidUtf8IsRejectedAtItsOffset(): void
    {
        $this->assertTokenizeFails(new MySqlDialect, "SELECT 'ä\xC3'", 'Invalid UTF-8', 10);
    }

    /**
     * @param  Closure(Dialect): bool  $predicate
     * @return array<string, array{Dialect}>
     */
    private static function dialectsWhere(Closure $predicate): array
    {
        return array_filter(self::allDialects(), static fn (array $arguments): bool => $predicate($arguments[0]));
    }

    private static function tripleQuotedStringDialect(): Dialect
    {
        return new class implements Dialect
        {
            use DialectDefaults;

            public function isDelimitedIdentifierStart(string $ch): bool
            {
                return $ch === '`';
            }

            public function isIdentifierStart(string $ch): bool
            {
                return Character::isAsciiAlphabetic($ch) || $ch === '_';
            }

            public function isIdentifierPart(string $ch): bool
            {
                return Character::isAsciiAlphabetic($ch) || Character::isAsciiDigit($ch) || $ch === '_';
            }

            public function supportsStringLiteralBackslashEscape(): bool
            {
                return true;
            }

            public function supportsTripleQuotedString(): bool
            {
                return true;
            }
        };
    }

    /**
     * @param  list<array{TokenKind, string, ?string}>  $expected
     * @return list<Token>
     */
    private function assertTokens(Dialect $dialect, string $sql, array $expected): array
    {
        $tokens = (new Tokenizer($dialect, $sql))->tokenize();

        $this->assertSame($expected, self::describe($tokens), sprintf('Tokenized differently for %s', $dialect::class));

        return $tokens;
    }

    private function assertTokenizeFails(Dialect $dialect, string $sql, string $reason, ?int $offset = null): void
    {
        try {
            (new Tokenizer($dialect, $sql))->tokenize();
        } catch (TokenizerException $exception) {
            if ($offset === null) {
                $this->assertStringStartsWith("{$reason} at byte offset ", $exception->getMessage(), $sql);

                return;
            }
            $this->assertSame("{$reason} at byte offset {$offset}", $exception->getMessage(), $sql);
            $this->assertSame($offset, $exception->offset);

            return;
        }

        $this->fail("Tokenizing {$sql} did not fail.");
    }

    private function assertNoCopyData(Dialect $dialect, string $sql): void
    {
        $tokens = (new Tokenizer($dialect, $sql))->tokenize();

        $this->assertSame([], array_filter($tokens, static fn (Token $token): bool => $token->is(TokenKind::CopyData)), $sql);
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<array{TokenKind, string, ?string}>
     */
    private static function describe(array $tokens): array
    {
        return array_map(static fn (Token $token): array => [$token->kind, $token->value, $token->quoteStyle], $tokens);
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<int>
     */
    private static function offsets(array $tokens): array
    {
        return array_map(static fn (Token $token): int => $token->offset, $tokens);
    }

    /**
     * @return list<array{TokenKind, string, ?string}>
     */
    private static function selectCustomerWhereIdIsOne(): array
    {
        return [
            self::word('SELECT'),
            self::space(),
            self::symbol(TokenKind::Mul, '*'),
            self::space(),
            self::word('FROM'),
            self::space(),
            self::word('customer'),
            self::space(),
            self::word('WHERE'),
            self::space(),
            self::word('id'),
            self::space(),
            self::symbol(TokenKind::Eq, '='),
            self::space(),
            self::number('1'),
        ];
    }

    /**
     * @param  list<array{TokenKind, string, ?string}>  $operators
     * @return list<array{TokenKind, string, ?string}>
     */
    private static function columnComparisons(string $pattern, array $operators): array
    {
        $expected = [self::word('SELECT'), self::space()];
        foreach ($operators as $i => $operator) {
            if ($i > 0) {
                array_push($expected, self::symbol(TokenKind::Comma, ','), self::space());
            }
            array_push($expected, self::word('col'), self::space(), $operator, self::space(), self::string($pattern));
        }

        return $expected;
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function word(string $value, ?string $quoteStyle = null): array
    {
        return [TokenKind::Word, $value, $quoteStyle];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function number(string $text): array
    {
        return [TokenKind::Number, $text, null];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function string(string $value): array
    {
        return [TokenKind::SingleQuotedString, $value, null];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function literal(TokenKind $kind, string $value, ?string $quoteStyle = null): array
    {
        return [$kind, $value, $quoteStyle];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function symbol(TokenKind $kind, string $text): array
    {
        return [$kind, $text, null];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function space(): array
    {
        return [TokenKind::Whitespace, '', null];
    }

    /**
     * @return array{TokenKind, string, ?string}
     */
    private static function comment(string $text): array
    {
        return [TokenKind::Whitespace, $text, null];
    }
}
