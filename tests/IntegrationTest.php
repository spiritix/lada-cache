<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Engine;

class IntegrationTest extends TestCase
{
    private $cache;

    public function setUp()
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
        $this->cache->flush();
    }

    public function testInsert()
    {
        $this->factory->times(5)->create(Car::class);

        $tableBuilder = Car::where(1, '=', 1);
        $tableBuilder->get();

        $rowBuilder = Car::where('id', '=', 1);
        $rowBuilder->get();

        $this->assertTrue($this->hasQuery($tableBuilder->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder->getQuery()));

        $car = new Car();
        $car->name = 'Car';
        $car->engine_id = 1;
        $car->driver_id = 1;
        $car->save();

        $this->assertFalse($this->hasQuery($tableBuilder->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder->getQuery()));
    }

    public function testUpdate()
    {
        $this->factory->times(5)->create(Car::class);

        $tableBuilder = Car::where(1, '=', 1);
        $tableBuilder->get();

        $rowBuilder1 = Car::where('id', '=', 1);
        $rowBuilder1->get();

        $rowBuilder2 = Car::where('id', '=', 2);
        $rowBuilder2->get();

        $this->assertTrue($this->hasQuery($tableBuilder->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder1->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder2->getQuery()));

        $car = Car::find(1);
        $car->name = 'New';
        $car->save();

        $this->assertFalse($this->hasQuery($tableBuilder->getQuery()));
        $this->assertFalse($this->hasQuery($rowBuilder1->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder2->getQuery()));
    }

    public function testDelete()
    {
        $this->factory->times(5)->create(Car::class);

        $tableBuilder = Car::where(1, '=', 1);
        $tableBuilder->get();

        $rowBuilder1 = Car::where('id', '=', 1);
        $rowBuilder1->get();

        $rowBuilder2 = Car::where('id', '=', 2);
        $rowBuilder2->get();

        $this->assertTrue($this->hasQuery($tableBuilder->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder1->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder2->getQuery()));

        Car::find(1)->delete();

        $this->assertFalse($this->hasQuery($tableBuilder->getQuery()));
        $this->assertFalse($this->hasQuery($rowBuilder1->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder2->getQuery()));
    }

    public function testOneToOneRelation()
    {
        //$this->factory->times(5)
        //    ->create(Car::class)
        //    ->each(function($car) {
        //        $car->engine()->save($this->factory->create(Engine::class));
        //    });
        //
        //$tableBuilder = Car::with(['engine' => function($query) {
        //    $query->where('name', '!=', 'xxx');
        //}])->where(1, '=', 1);
        //$tableBuilder->get();
        //
        //dd($tableBuilder->getQuery()->toSql());die();
        //
        //$rowBuilder1 = Car::where('id', '=', 1);
        //$rowBuilder1->get();
        //
        //$rowBuilder2 = Car::where('id', '=', 2);
        //$rowBuilder2->get();
    }

    public function testOneToManyRelation()
    {

    }

    public function testManyToManyRelation()
    {

    }

    public function testTruncate()
    {
        $this->factory->times(5)->create(Car::class);

        $tableBuilder = Car::where(1, '=', 1);
        $tableBuilder->get();

        $rowBuilder = Car::where('id', '=', 1);
        $rowBuilder->get();

        $this->assertTrue($this->hasQuery($tableBuilder->getQuery()));
        $this->assertTrue($this->hasQuery($rowBuilder->getQuery()));

        Car::truncate();

        $this->assertFalse($this->hasQuery($tableBuilder->getQuery()));
        $this->assertFalse($this->hasQuery($rowBuilder->getQuery()));
    }

    private function hasQuery(QueryBuilder $builder, string $sqlOperation = 'select', array $values = [])
    {
        /** @var Reflector $reflector */
        $reflector = app()->make(Reflector::class, [$builder])
            ->setSqlOperation($sqlOperation)
            ->setValues($values);
        $hasher = app()->make(Hasher::class, [$reflector]);

        return $this->cache->has($hasher->getHash());
    }
}