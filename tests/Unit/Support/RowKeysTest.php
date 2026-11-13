<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\RowKeys;

class RowKeysTest extends TestCase
{
    public static function equivalentIntegerKeys(): iterable
    {
        yield 'int' => [[5], ['5']];
        yield 'numeric string' => [['5'], ['5']];
        yield 'leading zeros' => [['005'], ['5']];
        yield 'padded' => [[' 5 '], ['5']];
        yield 'plus sign' => [['+5'], ['5']];
        yield 'negative' => [['-05'], ['-5']];
        yield 'negative zero' => [['-0'], ['0']];
        yield 'zero' => [['000'], ['0']];
        yield 'integral float' => [[5.0], ['5']];
        yield 'boolean' => [[true, false], ['1', '0']];
        yield 'duplicates collapse' => [[5, '5', '05'], ['5']];
        yield 'beyond int range' => [['123456789012345678901234567890'], ['123456789012345678901234567890']];
    }

    #[DataProvider('equivalentIntegerKeys')]
    public function testIntegerKeysTheDatabaseConsidersEqualShareATag(array $values, array $expected): void
    {
        $this->assertSame($expected, RowKeys::canonicalize($values, false));
    }

    public static function ambiguousIntegerKeys(): iterable
    {
        yield 'string cast by mysql' => [['5abc']];
        yield 'fraction' => [[5.5]];
        yield 'fractional string' => [['5.0']];
        yield 'exponent' => [['1e3']];
        yield 'uuid' => [['550e8400-e29b-41d4-a716-446655440000']];
        yield 'one ambiguous among many' => [[1, 2, 'x']];
    }

    #[DataProvider('ambiguousIntegerKeys')]
    public function testIntegerKeysTheDatabaseMayCastGiveUpOnRowTags(array $values): void
    {
        $this->assertNull(RowKeys::canonicalize($values, false));
    }

    public function testStringKeysIgnoreAsciiCase(): void
    {
        $this->assertSame(['abc-1_x'], RowKeys::canonicalize(['ABC-1_x', 'abc-1_X'], true));
    }

    public static function ambiguousStringKeys(): iterable
    {
        yield 'accent' => [['café']];
        yield 'trailing space' => [['abc ']];
        yield 'integer against string column' => [[5]];
        yield 'punctuation' => [['a.b']];
    }

    #[DataProvider('ambiguousStringKeys')]
    public function testStringKeysACollationMayEquateGiveUpOnRowTags(array $values): void
    {
        $this->assertNull(RowKeys::canonicalize($values, true));
    }

    public function testTooManyKeysGiveUpOnRowTags(): void
    {
        $this->assertCount(RowKeys::MAX_PER_TABLE, RowKeys::canonicalize(range(1, RowKeys::MAX_PER_TABLE), false));
        $this->assertNull(RowKeys::canonicalize(range(1, RowKeys::MAX_PER_TABLE + 1), false));
    }

    public function testNoKeysMeansNoRows(): void
    {
        $this->assertSame([], RowKeys::canonicalize([], false));
    }
}
