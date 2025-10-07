<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector;

class ManagerTest extends TestCase
{
    private $stub;

    public function setUp(): void
    {
        parent::setUp();

        $this->stub = $this->getMockBuilder(Reflector::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->stub->method('getTables')
            ->will($this->returnValue(['table1', 'table2']));
    }

    public function testEnabled()
    {
        $this->app['config']->set('lada-cache.active', false);
        $manager = new Manager($this->stub);
        $this->assertFalse($manager->shouldCache());

        $this->app['config']->set('lada-cache.active', true);
        $manager = new Manager($this->stub);
        $this->assertTrue($manager->shouldCache());
    }

    public function testInclusive()
    {
        $this->app['config']->set('lada-cache.include_tables', ['table1', 'table2']);
        $manager = new Manager($this->stub);
        $this->assertTrue($manager->shouldCache());

        $this->app['config']->set('lada-cache.include_tables', ['table2']);
        $manager = new Manager($this->stub);
        $this->assertFalse($manager->shouldCache());

        $this->app['config']->set('lada-cache.include_tables', ['other']);
        $manager = new Manager($this->stub);
        $this->assertFalse($manager->shouldCache());
    }

    public function testExclusive()
    {
        $this->app['config']->set('lada-cache.include_tables', []);

        $this->app['config']->set('lada-cache.exclude_tables', ['table1', 'table2']);
        $manager = new Manager($this->stub);
        $this->assertFalse($manager->shouldCache());

        $this->app['config']->set('lada-cache.exclude_tables', ['table2']);
        $manager = new Manager($this->stub);
        $this->assertFalse($manager->shouldCache());

        $this->app['config']->set('lada-cache.exclude_tables', ['other']);
        $manager = new Manager($this->stub);
        $this->assertTrue($manager->shouldCache());
    }
}
