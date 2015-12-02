<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector\AbstractReflector;
use Spiritix\LadaCache\Cache;

class ManagerTest extends TestCase
{
    private $manager;

    public function setUp()
    {
        parent::setUp();

        $this->manager = app()->make('LadaCache');
    }

    public function testInstanceOf()
    {
        $this->assertInstanceOf(Manager::class, $this->manager);
    }

    public function testGetConfig()
    {
        $this->assertEquals(config('lada-cache'), $this->manager->getConfig());
    }

    public function testResolve()
    {
        $reflector = $this->getMockForAbstractClass(AbstractReflector::class);

        $this->assertInstanceOf(Cache::class, $this->manager->resolve($reflector));
    }

    public function testFlush()
    {
        $redis = $this->manager->getRedis();

        $redis->set($redis->prefix('key'), 'value');

        $this->manager->flush();

        $this->assertFalse($redis->exists($redis->prefix('key')));
    }
}