<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\LuaScripts;
use Spiritix\LadaCache\Support\ColumnKeys;
use Spiritix\LadaCache\Support\ColumnType;

final class ColumnKeysTest extends TestCase
{
    private const array UNKEYED_FOR_EVERY_TYPE = [ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, ColumnKeys::UNKEYED];

    public function testTheScriptsReadTheKeysOfEveryColumnTypeInOrder(): void
    {
        $types = implode("', '", array_map(static fn (ColumnType $type): string => $type->value, ColumnType::cases()));

        $this->assertStringContainsString("local columnTypes = {'{$types}'}\n", LuaScripts::LOOKUP);
        $this->assertStringContainsString("local columnTypes = {'{$types}'}\n", LuaScripts::INVALIDATE);
    }

    /**
     * @return array<string, array{list<mixed>, list<int|string>}>
     */
    public static function values(): array
    {
        return [
            'integers are numbers only, with one key for integer and decimal columns' => [[5, 7], [2, '5', '7', ColumnKeys::AS_BEFORE, ColumnKeys::UNKEYED, ColumnKeys::UNKEYED]],
            'integer strings are both, and text keeps their spelling' => [['05', '5'], [1, '5', ColumnKeys::AS_BEFORE, 2, '05', '5', ColumnKeys::UNKEYED]],
            'decimals compare by value' => [['1.50', '01.5', '-0.0'], [ColumnKeys::UNKEYED, 2, '0', '1.5', ColumnKeys::UNKEYED, ColumnKeys::UNKEYED]],
            'strings a number column would cast are text only' => [['1abc'], [ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, 1, '1abc', ColumnKeys::UNKEYED]],
            'an exponent is cast, not compared' => [['1e2'], [ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, 1, '1e2', ColumnKeys::UNKEYED]],
            'uuids ignore case, hyphens and braces' => [
                ['A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11', '{a0eebc999c0b4ef8bb6d6bb9bd380a11}'],
                [ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, ColumnKeys::UNKEYED, 1, 'a0eebc999c0b4ef8bb6d6bb9bd380a11'],
            ],
            'a number among strings leaves no way to key a text column' => [[5, 'abc'], self::UNKEYED_FOR_EVERY_TYPE],
            'text a collation may fold has no key' => [['café'], self::UNKEYED_FOR_EVERY_TYPE],
            'no values' => [[], self::UNKEYED_FOR_EVERY_TYPE],
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
        $this->assertSame($arguments === self::UNKEYED_FOR_EVERY_TYPE, ColumnKeys::of($values)->isEmpty());
    }

    public function testMergingKeepsOnlyTheTypesBothSidesAreKeyedFor(): void
    {
        $this->assertSame([2, '1', '5', ColumnKeys::AS_BEFORE, ColumnKeys::UNKEYED, ColumnKeys::UNKEYED], ColumnKeys::of([5])->merge(ColumnKeys::of(['1']))->arguments());
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
            'uuid' => ['uuid', ColumnType::Uuid],
            'sql server uuid' => ['uniqueidentifier', ColumnType::Uuid],
            'decimal' => ['decimal(10,2)', ColumnType::Decimal],
            'postgres numeric' => ['numeric', ColumnType::Decimal],
            'date' => ['date', null],
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
