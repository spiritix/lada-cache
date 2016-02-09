<?php

namespace Spiritix\LadaCache\Tests\Reflector;

use Spiritix\LadaCache\Reflector\AbstractReflector AS AR;
use Spiritix\LadaCache\Tests\TestCase;

class AbstractReflectorTest extends TestCase
{
    private $stub;

    public function setUp()
    {
        parent::setUp();

        $this->stub = $this->getMockForAbstractClass(AR::class);
        $this->stub->setConfig(config('lada-cache'));

        $this->stub->method('getDatabase')
            ->will($this->returnValue('database'));

        $this->stub->method('getTables')
            ->will($this->returnValue(['table1', 'table2']));
    }

    public function testGetTagsWithoutTables()
    {
        $this->stub->method('getRows')
            ->will($this->returnValue([1, 2]));

        $expected = [
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1' . AR::PREFIX_ROW . '1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1' . AR::PREFIX_ROW . '2',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2' . AR::PREFIX_ROW . '1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2' . AR::PREFIX_ROW . '2',
        ];

        $this->assertEquals($expected, $this->stub->getTags(false));
    }

    public function testGetTagsWithTables()
    {
        $this->stub->method('getRows')
            ->will($this->returnValue([1, 2]));

        $expected = [
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1' . AR::PREFIX_ROW . '1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1' . AR::PREFIX_ROW . '2',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2' . AR::PREFIX_ROW . '1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2' . AR::PREFIX_ROW . '2',
        ];

        $this->assertEquals($expected, $this->stub->getTags(true));
    }

    public function testGetTagsWithoutRows()
    {
        $expected = [
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table1',
            AR::PREFIX_DATABASE . 'database' . AR::PREFIX_TABLE . 'table2',
        ];

        $this->assertEquals($expected, $this->stub->getTags(false));
        $this->assertEquals($expected, $this->stub->getTags(true));
    }
}