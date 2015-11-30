<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Reflector\AbstractReflector;

class AbstractReflectorTest extends TestCase
{
    public function testGetTagsWithRows()
    {
        $stub = $this->getMockForAbstractClass(AbstractReflector::class);

        $stub->method('getDatabase')
            ->willReturn('database');

        $stub->method('getTables')
            ->willReturn(['table1', 'table2']);

        $stub->method('getRows')
            ->willReturn([1, 2, 3]);

        $expected = [
            'd:databaset:table1',
            'd:databaset:table2',
            'd:databaset:table1r:1',
            'd:databaset:table1r:2',
            'd:databaset:table1r:3',
        ];

        $this->assertEquals($expected, $stub->getTags(true));

        $expected = [
            'd:databaset:table1r:1',
            'd:databaset:table1r:2',
            'd:databaset:table1r:3',
        ];

        $this->assertEquals($expected, $stub->getTags(false));
    }

    public function testGetTagsWithoutRows()
    {
        $stub = $this->getMockForAbstractClass(AbstractReflector::class);

        $stub->method('getDatabase')
            ->willReturn('database');

        $stub->method('getTables')
            ->willReturn(['table1', 'table2']);

        $stub->method('getRows')
            ->willReturn([]);

        $expected = [
            'd:databaset:table1',
            'd:databaset:table2',
        ];

        $this->assertEquals($expected, $stub->getTags(true));
        $this->assertEquals($expected, $stub->getTags(false));
    }
}