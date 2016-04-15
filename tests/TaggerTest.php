<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;

class TaggerTest extends TestCase
{
    private $stub;

    public function setUp()
    {
        parent::setUp();

        $this->stub = $this->getMockBuilder(Reflector::class)
            ->disableOriginalConstructor()
            ->getMock();

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
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1' . Tagger::PREFIX_ROW . '1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1' . Tagger::PREFIX_ROW . '2',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2' . Tagger::PREFIX_ROW . '1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2' . Tagger::PREFIX_ROW . '2',
        ];

        $tagger = new Tagger($this->stub, false);
        $this->assertEquals($expected, $tagger->getTags());
    }

    public function testGetTagsWithTables()
    {
        $this->stub->method('getRows')
            ->will($this->returnValue([1, 2]));

        $expected = [
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1' . Tagger::PREFIX_ROW . '1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1' . Tagger::PREFIX_ROW . '2',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2' . Tagger::PREFIX_ROW . '1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2' . Tagger::PREFIX_ROW . '2',
        ];

        $tagger = new Tagger($this->stub, true);
        $this->assertEquals($expected, $tagger->getTags());
    }

    public function testGetTagsWithoutRows()
    {
        $expected = [
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table1',
            Tagger::PREFIX_DATABASE . 'database' . Tagger::PREFIX_TABLE . 'table2',
        ];

        $tagger = new Tagger($this->stub, false);
        $this->assertEquals($expected, $tagger->getTags());

        $tagger = new Tagger($this->stub, true);
        $this->assertEquals($expected, $tagger->getTags());
    }
}