<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Write;

final class WriteTest extends TestCase
{
    public function testInsertsChangeViewsAndFireInsertTriggers(): void
    {
        $this->assertSame(['a:cars', 'gi:cars'], Write::insert()->dependentsFields('cars'));
    }

    public function testUpdatesFireTheTriggersAndRulesOfTheColumnsTheyAssign(): void
    {
        $this->assertSame(
            ['a:cars', 'gu:cars', 'gc:cars:id', 'gc:cars:options', 'gc:cars:name', 'u:cars:id', 'u:cars:options', 'u:cars:name'],
            Write::update(['id', 'cars.ID', 'options->colour', '`name`'])->dependentsFields('cars'),
        );
    }

    public function testDeletesFireDeleteTriggersAndRules(): void
    {
        $this->assertSame(['a:cars', 'gd:cars', 'd:cars'], Write::delete()->dependentsFields('cars'));
    }

    public function testTruncatesFireTruncateAndDeleteTriggersAndEmptyReferencingTables(): void
    {
        $this->assertSame(['a:cars', 'gt:cars', 'gd:cars', 't:cars'], Write::truncate()->dependentsFields('cars'));
    }

    public function testMergesFireWhatAnyInsertDeleteOrUpdateFires(): void
    {
        $this->assertSame(['a:cars', 'gi:cars', 'gu:cars', 'gc:cars', 'gd:cars', 'd:cars', 'u:cars'], Write::merge()->dependentsFields('cars'));
    }

    public function testUnknownWritesFireEverything(): void
    {
        $this->assertSame(['a:cars', 'gi:cars', 'gu:cars', 'gd:cars', 'gt:cars', 'gc:cars', 't:cars', 'u:cars'], Write::unknown()->dependentsFields('cars'));
    }

    public function testWritesAffectingNoRowsFireNoForeignKeyButStillTheirTriggers(): void
    {
        $this->assertSame(['a:cars', 'gd:cars'], Write::delete()->affecting(0)->dependentsFields('cars'));
        $this->assertSame(['a:cars', 'gu:cars', 'gc:cars:id'], Write::update(['id'])->affecting(0)->dependentsFields('cars'));
        $this->assertSame(['a:cars', 'gd:cars', 'd:cars'], Write::delete()->affecting(2)->dependentsFields('cars'));
    }
}
