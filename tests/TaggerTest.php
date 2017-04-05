<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Engine;

class TaggerTest extends TestCase
{
    private $cache;

    public function setUp()
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
        $this->cache->flush();
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars
     * Generates tags like: table_unspecific_cars
     */
    public function testSelectWithoutCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        $expectedTags = [$this->getUnspecificTableTag($car->getTable())];
        $generatedTags = $this->getTags($sqlBuilder);

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars WHERE name LIKE ‘%XX%’
     * Generates tags like: table_unspecific_cars
     */
    public function testSelectWithUnspecificWhere()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        $sqlBuilder = $car->where('name', 'like', '%XX%');
        $sqlBuilder->get();

        $expectedTags = [$this->getUnspecificTableTag($car->getTable())];
        $generatedTags = $this->getTags($sqlBuilder);

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars WHERE name IN(1,4,5)
     * Generates tags like: table_specific_cars, cars_row_1, cars_row_4, cars_row_5
     */
    public function testSelectWithInCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        $rowsSelected = [1, 4, 5];

        $sqlBuilder = $car->whereIn('id', $rowsSelected);
        $sqlBuilder->get();

        $expectedTags = [$this->getSpecificTableTag($car->getTable()),];
        $generatedTags = $this->getTags($sqlBuilder);

        foreach ($rowsSelected as $rowId) {
            $expectedTags[] = $this->getRowTag($car->getTable(), $rowId);
        }

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars JOIN engines ON e.id = c.engine_id WHERE e.name LIKE ‘%XX%’
     * Generates tags like: table_unspecific_cars, table_unspecific_engines
     */
    public function testSelectWithInConditionAndSpecificIdContidionVariant1()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'XX';
                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);

        /** @var Engine $engine */
        $engine = app(Engine::class);

        $sqlBuilder = $car->join(
            $engine->getTable(),
            $engine->getTable() . '.id',
            '=',
            $car->getTable() . '.engine_id'
        )->where($engine->getTable() . '.name', 'LIKE', '%XX%');

        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getUnspecificTableTag($engine->getTable()),
        ];

        $generatedTags = $this->getTags($sqlBuilder);

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars JOIN engines ON e.id = c.engine_id WHERE e.id IN(2,4)
     * Generates tags like: table_unspecific_cars, table_specific_engines, engines_row_2, engines_row_4
     */
    public function testSelectWithInConditionAndSpecificIdConditionVariant2()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'XX';
                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);

        /** @var Engine $engine */
        $engine = app(Engine::class);

        $rowsSelected = [2, 4];

        $sqlBuilder = $car->join(
            $engine->getTable(),
            $engine->getTable() . '.id',
            '=',
            $car->getTable() . '.engine_id'
        )->whereIn($engine->getTable() . '.id', $rowsSelected);

        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($engine->getTable()),
        ];

        $generatedTags = $this->getTags($sqlBuilder);

        foreach ($rowsSelected as $rowId) {
            $expectedTags[] = $this->getRowTag($engine->getTable(), $rowId);
        }

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars JOIN engines ON e.id = c.engine_id WHERE c.id = 1 OR e.id IN(2,4)
     * Generates tags like:  table_specific_cars, table_specific_engines, cars_row_1,  engines_row_2, engines_row_4
     */
    public function testSelectWithJoinAndInConditionAndSpecificIdCondition()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'XX';
                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);

        /** @var Engine $engine */
        $engine = app(Engine::class);

        $carId = 1;
        $enginesSelected = [2, 4];

        $sqlBuilder = $car->join(
            $engine->getTable(),
            $engine->getTable() . '.id',
            '=',
            $car->getTable() . '.engine_id'
        )->where($car->getTable() . '.id', '=', $carId)
        ->orWhereIn($engine->getTable() . '.id', $enginesSelected);

        $sqlBuilder->get();

        $expectedTags = [
            $this->getSpecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $carId),
            $this->getSpecificTableTag($engine->getTable()),
        ];

        $generatedTags = $this->getTags($sqlBuilder);

        foreach ($enginesSelected as $rowId) {
            $expectedTags[] = $this->getRowTag($engine->getTable(), $rowId);
        }

        $this->assertCacheHasTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * INSERT INTO cars …….
     * Invalidates tags like:  table_unspecific_cars
     */
    public function testInsertWithoutCondition()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'XX';
                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);

        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        $expectedTags = [$this->getUnspecificTableTag($car->getTable()),];
        $this->assertCacheHasTags($expectedTags);

        $valuesToBeInserted = [
            'name'      => 'XX',
            'engine_id' => 1,
            'driver_id' => 1,
        ];

        $sqlBuilder = $car->newInstance();
        $sqlBuilder->name = $valuesToBeInserted['name'];
        $sqlBuilder->engine_id = $valuesToBeInserted['engine_id'];
        $sqlBuilder->driver_id = $valuesToBeInserted['driver_id'];
        $sqlBuilder->save();

        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_INSERT, $valuesToBeInserted);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * UPDATE cars SET name = ‘XX’
     * Invalidates tags like:  table_unspecific_cars, table_specific_cars
     */
    public function testUpdateWithUnspecificCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the table_specific_cars tag in cache
        $sqlBuilder = $car->where('id', '=', 1);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where(1, '=', 1);

        $valuesToBeUpdated = [
            'name' => 'XX',
        ];

        $sqlBuilder->update($valuesToBeUpdated);
        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_UPDATE, $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * UPDATE cars SET name = ‘XX’ WHERE id = 1
     * Invalidates tags like:  table_unspecific_cars, cars_row_1
     */
    public function testUpdateWithSpecificIdCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;

        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $rowId),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where('id', '=', $rowId);

        $valuesToBeUpdated = [
            'name' => 'XX',
        ];

        $sqlBuilder->update($valuesToBeUpdated);
        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_UPDATE, $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * UPDATE cars SET name = ‘XX’ WHERE id IN(1,4)
     * Invalidates tags like: table_unspecific_cars, cars_row_1, cars_row_4
     */
    public function testUpdateWithSpecificInCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $firstRow = 1;
        $sqlBuilder = $car->where('id', '=', $firstRow);
        $sqlBuilder->get();

        // Generating the cars_row_4 tag in cache, also generates the tag table_specific_cars
        $secondRow = 4;
        $sqlBuilder = $car->where('id', '=', $secondRow);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $firstRow),
            $this->getRowTag($car->getTable(), $secondRow),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereIn('id', [$firstRow, $secondRow]);

        $valuesToBeUpdated = [
            'name' => 'XX',
        ];

        $sqlBuilder->update($valuesToBeUpdated);
        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_UPDATE, $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * DELETE FROM cars WHERE name LIKE ‘XX’
     * Invalidates tags like: table_unspecific_cars, table_specific_cars
     */
    public function testDeleteWithUnspecificCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;
        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where('name', 'like', '&XX%');
        $sqlBuilder->delete();

        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_DELETE, []);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * DELETE FROM cars WHERE id = 1
     * Invalidates tags like: table_unspecific_cars, cars_row_1
     */
    public function testDeleteWithSpecificIdCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;

        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $rowId),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereId($rowId);
        $sqlBuilder->delete();

        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_DELETE, []);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * DELETE FROM cars WHERE id IN(1,4)
     * Invalidates tags like: table_unspecific_cars, cars_row_1, cars_row_4
     */
    public function testDeleteWithSpecificInIdCondition()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $firstRow = 1;
        $sqlBuilder = $car->where('id', '=', $firstRow);
        $sqlBuilder->get();

        // Generating the cars_row_4 tag in cache, also generates the tag table_specific_cars
        $secondRow = 4;
        $sqlBuilder = $car->where('id', '=', $secondRow);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $firstRow),
            $this->getRowTag($car->getTable(), $secondRow),
        ];

        $this->assertCacheHasTags($expectedTags);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereIn('id', [$firstRow, $secondRow]);
        $sqlBuilder->delete();

        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_DELETE, []);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    /**
     * Testing that
     *
     * TRUNCATE TABLE cars
     * Invalidates tags like: table_specific_cars, table_unspecific_cars
     */
    public function testTruncate()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        // Generating table_unspecific_cars tag
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // Generating table_specific_cars tag
        $sqlBuilder = $car->where('id', '=', 1);
        $sqlBuilder->get();

        $expectedTags = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($expectedTags);

        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->truncate();

        $generatedTags = $this->getTags($sqlBuilder, Reflector::QUERY_TYPE_TRUNCATE, []);

        $this->assertCacheDoesNotHaveTags($expectedTags);
        $this->assertCountEquals($expectedTags, $generatedTags);
    }

    private function assertCacheHasTags(array $tags)
    {
        foreach ($tags as $tag) {
            $this->assertTrue($this->cache->has($tag), 'Tag not found in cache: ' . $tag);
        }
    }

    private function assertCacheDoesNotHaveTags(array $tags)
    {
        foreach ($tags as $tag) {
            $this->assertFalse($this->cache->has($tag), 'Tag found in cache: ' . $tag);
        }
    }

    private function assertCountEquals($expectedTags, $generatedTags)
    {
        $this->assertEquals(
            count($expectedTags),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
    }

    private function getUnspecificTableTag($table)
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_UNSPECIFIC . $table;
    }

    private function getSpecificTableTag($table)
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_SPECIFIC . $table;
    }

    private function getRowTag($table, $rowId)
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_SPECIFIC . $table . Tagger::PREFIX_ROW . $rowId;
    }

    private function getTags($tableBuilder, $sqlOperation = Reflector::QUERY_TYPE_SELECT, $values = [])
    {
        /** @var Reflector $reflector */
        $reflector = new Reflector($tableBuilder->getQuery(), $sqlOperation, $values);

        /** @var Tagger $tagger */
        $tagger = new Tagger($reflector);

        return $tagger->getTags();
    }
}