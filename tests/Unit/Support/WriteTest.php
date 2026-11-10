<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Write;

final class WriteTest extends TestCase
{
    public function testInsertsChangeWhatEveryWriteChanges(): void
    {
        $this->assertSame(['a:cars'], Write::insert()->dependentsFields('cars'));
    }

    public function testUpdatesChangeWhatTheirAssignedColumnsFire(): void
    {
        $this->assertSame(
            ['a:cars', 'u:cars:id', 'u:cars:options', 'u:cars:name'],
            Write::update(['id', 'cars.ID', 'options->colour', '`name`'])->dependentsFields('cars'),
        );
    }

    public function testDeletesChangeWhatTheirDeleteRulesFire(): void
    {
        $this->assertSame(['d:cars'], Write::delete()->dependentsFields('cars'));
    }

    public function testUnknownWritesChangeWhatAnyDeleteOrUpdateFires(): void
    {
        $this->assertSame(['t:cars', 'u:cars'], Write::unknown()->dependentsFields('cars'));
    }

    public function testWritesAffectingNoRowsFireNoForeignKey(): void
    {
        $this->assertSame(['a:cars'], Write::delete()->affecting(0)->dependentsFields('cars'));
        $this->assertSame(['a:cars'], Write::update(['id'])->affecting(0)->dependentsFields('cars'));
        $this->assertSame(['d:cars'], Write::delete()->affecting(2)->dependentsFields('cars'));
    }
}
