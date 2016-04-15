<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;

class HasherTest extends TestCase
{
    private $hasher;

    public function setUp()
    {
        parent::setUp();

        $this->hasher = new Hasher($this->getStub());
    }

    public function testHash()
    {
        $this->assertInternalType('string', $this->hasher->getHash());
    }

    public function testDatabase()
    {
        $stub = $this->getStub('database');

        $stub->method('getDatabase')
            ->will($this->returnValue('other_db'));

        $hasher = new Hasher($stub);

        $this->assertNotEquals($hasher->getHash(), $this->hasher->getHash());
    }

    public function testSql()
    {
        $stub = $this->getStub('sql');

        $stub->method('getSql')
            ->will($this->returnValue("SELECT * ROM other_table"));

        $hasher = new Hasher($stub);

        $this->assertNotEquals($hasher->getHash(), $this->hasher->getHash());
    }

    public function testParameters()
    {
        $stub = $this->getStub('parameters');

        $stub->method('getParameters')
            ->will($this->returnValue([':other' => 'parameter']));

        $hasher = new Hasher($stub);

        $this->assertNotEquals($hasher->getHash(), $this->hasher->getHash());
    }

    private function getStub($without = '')
    {
        $stub = $this->getMockBuilder(Reflector::class)
            ->disableOriginalConstructor()
            ->getMock();

        if ($without !== 'database') {
            $stub->method('getDatabase')
                ->will($this->returnValue('my_database'));
        }

        if ($without !== 'sql') {
            $stub->method('getSql')
                ->will($this->returnValue("SELECT * ROM my_table WHERE parameter = :parameter AND parameter2 = :parameter2 AND id IN(1,2,3)"));
        }

        if ($without !== 'parameters') {
            $stub->method('getParameters')
                ->will($this->returnValue([':parameter' => 'value', ':parameter2' => 'value2']));
        }

        return $stub;
    }
}