<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class IntegrationTest extends TestCase
{
    private $cache;

    public function setUp()
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
    }

    public function testInsert()
    {
        $this->factory->create(Car::class);

        $builder = Car::where(1, '=', 1);
        $builder->get();

        $this->assertTrue($this->hasQuery($builder->getQuery()));

        $car = new Car();
        $car->name = 'Car';
        $car->engine_id = 1;
        $car->driver_id = 1;
        $car->save();

        $this->assertFalse($this->hasQuery($builder->getQuery()));
    }

    public function testUpdate()
    {
        $this->factory->create(Car::class);

        $builder = Car::where(1, '=', 1);
        $builder->get();

        $this->assertTrue($this->hasQuery($builder->getQuery()));

        $car = Car::find(1);
        $car->name = 'New';
        $car->save();

        $this->assertFalse($this->hasQuery($builder->getQuery()));
    }

    public function testDelete()
    {
        $this->factory->create(Car::class);

        $builder = Car::where(1, '=', 1);
        $builder->get();

        $this->assertTrue($this->hasQuery($builder->getQuery()));

        Car::find(1)->delete();

        $this->assertFalse($this->hasQuery($builder->getQuery()));
    }

    public function testTruncate()
    {
        $this->factory->create(Car::class);

        $builder = Car::where(1, '=', 1);
        $builder->get();

        $this->assertTrue($this->hasQuery($builder->getQuery()));

        Car::truncate();

        $this->assertFalse($this->hasQuery($builder->getQuery()));
    }

    private function hasQuery(QueryBuilder $builder)
    {
        $reflector = app()->make(Reflector::class, [$builder]);
        $hasher = app()->make(Hasher::class, [$reflector]);

        return $this->cache->has($hasher->getHash());
    }
}