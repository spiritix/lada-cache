<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\ColumnKeys;
use Spiritix\LadaCache\Support\ColumnType;

final class ColumnKeysTest extends TestCase
{
    /**
     * @return array<string, array{list<mixed>, list<int|string>}>
     */
    public static function values(): array
    {
        return [
            'integers are numbers only' => [[5, 7], [2, '5', '7', -1]],
            'integer strings are both, and text keeps their spelling' => [['05', '5'], [1, '5', 2, '05', '5']],
            'strings a number column would cast are text only' => [['1abc'], [-1, 1, '1abc']],
            'a number among strings leaves no way to key a text column' => [[5, 'abc'], [-1, -1]],
            'text a collation may fold has no key' => [['café'], [-1, -1]],
            'no values' => [[], [-1, -1]],
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<int|string>  $arguments
     */
    #[DataProvider('values')]
    public function testValuesAreKeyedForEachColumnTypeThatComparesThemExactly(array $values, array $arguments): void
    {
        $this->assertSame($arguments, ColumnKeys::of($values)->arguments());
        $this->assertSame($arguments === [-1, -1], ColumnKeys::of($values)->isEmpty());
    }

    public function testMergingKeepsOnlyTheTypesBothSidesAreKeyedFor(): void
    {
        $this->assertSame([2, '1', '5', -1], ColumnKeys::of([5])->merge(ColumnKeys::of(['1']))->arguments());
        $this->assertTrue(ColumnKeys::of([5])->merge(ColumnKeys::of(['abc']))->isEmpty());
    }

    public function testMergingMoreKeysThanATableMayHaveLeavesNone(): void
    {
        $this->assertTrue(ColumnKeys::of(range(1, 60))->merge(ColumnKeys::of(range(61, 120)))->isEmpty());
    }

    /**
     * @return array<string, array{string, ColumnType|null}>
     */
    public static function declaredTypes(): array
    {
        return [
            'mysql bigint' => ['bigint', ColumnType::Integer],
            'mysql column type' => ['int unsigned', ColumnType::Integer],
            'postgres int8' => ['int8', ColumnType::Integer],
            'sqlite integer' => ['INTEGER', ColumnType::Integer],
            'varchar with a length' => ['varchar(255)', ColumnType::Text],
            'postgres padded char' => ['bpchar', ColumnType::Text],
            'sql server nvarchar' => ['nvarchar', ColumnType::Text],
            'uuid' => ['uuid', null],
            'decimal' => ['decimal(10,2)', null],
            'interval is no integer' => ['interval', null],
            'unknown' => ['', null],
        ];
    }

    #[DataProvider('declaredTypes')]
    public function testDeclaredTypesAreReadAsHowTheyCompare(string $declared, ?ColumnType $expected): void
    {
        $this->assertSame($expected, ColumnType::of($declared));
    }
}
