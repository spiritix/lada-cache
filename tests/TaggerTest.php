<?php
/**
 * This file is part of Event Commander.
 *
 * @copyright  Copyright (c) 2015 and Onwards, Smartbridge GmbH <info@smartbridge.ch>. All rights reserved.
 * @license    Proprietary/Closed Source
 * @see        http://www.eventcommander.com
 */

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Engine;

/**
 * Testing the new tag generation scheme scheme.
 *
 * @package Spiritix\LadaCache\Tests
 * @author  Marian Hodorogea <marian.hodorogea@smartbridge.ch>
 */
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

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [];
        $tagsExpectedToBeFound[] = $this->getUnspecificTableTag($car->getTable());

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars WHERE name LIKE ‘%XX%’
     * Generates tags like: table_unspecific_cars
     */
    public function testSelectWithUnspeficicWhere()
    {
        $this->factory->times(5)->create(Car::class);

        /** @var Car $car */
        $car = app(Car::class);

        $sqlBuilder = $car->where('name', 'like', '%XX%');
        $sqlBuilder->get();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [
            $this->getSpecificTableTag($car->getTable()),
        ];

        foreach ($rowsSelected as $rowId) {
            $tagsExpectedToBeFound[] = $this->getRowTag($car->getTable(), $rowId);
        }

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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
                $engine->name = 'Engine for car ' . $car->id;

                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);
        /** @var Engine $engine */
        $engine = app(Engine::class);

        $sqlBuilder = $car
            ->join(
                $engine->getTable(),
                $engine->getTable() . '.id',
                '=',
                $car->getTable() . '.engine_id'
            )
            ->where($engine->getTable() . '.name', 'LIKE', '%XX%');
        $sqlBuilder->get();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getUnspecificTableTag($engine->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars JOIN engines ON e.id = c.engine_id WHERE e.id IN(2,4)
     * Generates tags like: table_unspecific_cars, table_specific_engines, engines_row_2, engines_row_4
     */
    public function testSelectWithInConditionAndSpecificIdContidionVariant2()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'Engine for car ' . $car->id;

                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);
        /** @var Engine $engine */
        $engine = app(Engine::class);

        $rowsSelected = [2, 4];
        $sqlBuilder = $car
            ->join(
                $engine->getTable(),
                $engine->getTable() . '.id',
                '=',
                $car->getTable() . '.engine_id'
            )
            ->whereIn($engine->getTable() . '.id', $rowsSelected);

        $sqlBuilder->get();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($engine->getTable()),
        ];

        foreach ($rowsSelected as $rowId) {
            $tagsExpectedToBeFound[] = $this->getRowTag($engine->getTable(), $rowId);
        }

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
    }

    /**
     * Testing that
     *
     * SELECT * FROM cars JOIN engines ON e.id = c.engine_id WHERE c.id = 1 OR e.id IN(2,4)
     * Generates tags like:  table_specific_cars, table_specific_engines, cars_row_1,  engines_row_2, engines_row_4
     */
    public function testSelectWithJoinAndInConditionAndSpecificIdContidion()
    {
        $this->factory->times(5)->create(Car::class)
            ->each(function ($car) {
                $engine = app(Engine::class);
                $engine->name = 'Engine for car ' . $car->id;

                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);
        /** @var Engine $engine */
        $engine = app(Engine::class);

        $enginesSelected = [2, 4];
        $carId = 1;
        $sqlBuilder = $car
            ->join(
                $engine->getTable(),
                $engine->getTable() . '.id',
                '=',
                $car->getTable() . '.engine_id'
            )
            ->where($car->getTable() . '.id', '=', $carId)
            ->orWhereIn($engine->getTable() . '.id', $enginesSelected);

        $sqlBuilder->get();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'select');

        $tagsExpectedToBeFound = [
            $this->getSpecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $carId),
            $this->getSpecificTableTag($engine->getTable()),
        ];

        foreach ($enginesSelected as $rowId) {
            $tagsExpectedToBeFound[] = $this->getRowTag($engine->getTable(), $rowId);
        }

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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
                $engine->name = 'Engine for car ' . $car->id;

                $car->engine()->save($engine);
            });

        /** @var Car $car */
        $car = app(Car::class);

        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $valuesToBeInserted = [
            'name'      => 'new car',
            'engine_id' => 1,
            'driver_id' => 1,
        ];

        $sqlBuilder = $car->newInstance();
        $sqlBuilder->name = $valuesToBeInserted['name'];
        $sqlBuilder->engine_id = $valuesToBeInserted['engine_id'];
        $sqlBuilder->driver_id = $valuesToBeInserted['driver_id'];
        $sqlBuilder->save();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'insert', $valuesToBeInserted);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the table_specific_cars tag in cache
        $sqlBuilder = $car->where('id', '=', 1);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where(1, '=', 1);
        $valuesToBeUpdated = [
            'name' => 'XX',
        ];
        $sqlBuilder->update($valuesToBeUpdated);

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'update', $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;
        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $rowId),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where('id', '=', $rowId);
        $valuesToBeUpdated = [
            'name' => 'XX',
        ];
        $sqlBuilder->update($valuesToBeUpdated);

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'update', $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $firstRow = 1;
        $sqlBuilder = $car->where('id', '=', $firstRow);
        $sqlBuilder->get();

        // generating the cars_row_4 tag in cache, also generates the tag table_specific_cars
        $secondRow = 4;
        $sqlBuilder = $car->where('id', '=', $secondRow);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $firstRow),
            $this->getRowTag($car->getTable(), $secondRow),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereIn('id', [$firstRow, $secondRow]);
        $valuesToBeUpdated = [
            'name' => 'XX',
        ];
        $sqlBuilder->update($valuesToBeUpdated);

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'update', $valuesToBeUpdated);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;
        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->where('name', 'like', '&XX%');
        $sqlBuilder->delete();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'delete', []);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $rowId = 1;
        $sqlBuilder = $car->where('id', '=', $rowId);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $rowId),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereId($rowId);
        $sqlBuilder->delete();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'delete', []);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        // generating the table_unspecific_cars tag in cache
        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->get();

        // generating the cars_row_1 tag in cache, also generates the tag table_specific_cars
        $firstRow = 1;
        $sqlBuilder = $car->where('id', '=', $firstRow);
        $sqlBuilder->get();

        // generating the cars_row_4 tag in cache, also generates the tag table_specific_cars
        $secondRow = 4;
        $sqlBuilder = $car->where('id', '=', $secondRow);
        $sqlBuilder->get();

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getRowTag($car->getTable(), $firstRow),
            $this->getRowTag($car->getTable(), $secondRow),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        // Invalidating tags table_unspecific_cars, table_specific_cars from cache
        $sqlBuilder = $car->whereIn('id', [$firstRow, $secondRow]);
        $sqlBuilder->delete();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'delete', []);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
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

        $tagsExpectedToBeFound = [
            $this->getUnspecificTableTag($car->getTable()),
            $this->getSpecificTableTag($car->getTable()),
        ];

        $this->assertCacheHasTags($tagsExpectedToBeFound);

        $sqlBuilder = $car->where(1, '=', 1);
        $sqlBuilder->truncate();

        $generatedTags = $this->getGeneratedTags($sqlBuilder, 'truncate', []);

        $this->assertCacheDoesNotHaveTags($tagsExpectedToBeFound);

        $this->assertEquals(
            count($tagsExpectedToBeFound),
            count($generatedTags),
            'Generated more or less tags than expected.'
        );
    }

    private function assertCacheHasTags(array $tags)
    {
        foreach ($tags as $tag) {
            $this->assertTrue(
                $this->cache->has($tag),
                'Tag not found in cache: ' . $tag
            );
        }
    }

    private function assertCacheDoesNotHaveTags(array $tags)
    {
        foreach ($tags as $tag) {
            $this->assertFalse(
                $this->cache->has($tag),
                'Tag found in cache: ' . $tag
            );
        }
    }

    private function getUnspecificTableTag(string $table): string
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_UNSPECIFIC . $table;
    }

    private function getSpecificTableTag(string $table): string
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_SPECIFIC . $table;
    }

    private function getRowTag(string $table, int $rowId)
    {
        return Tagger::PREFIX_DATABASE . ':memory:' . Tagger::PREFIX_TABLE_SPECIFIC . $table . Tagger::PREFIX_ROW . $rowId;
    }

    private function getGeneratedTags($tableBuilder, string $sqlOperation, array $values = []): array
    {
        /** @var Reflector $reflector */
        $reflector = app(Reflector::class, [$tableBuilder->getQuery()])
            ->setSqlOperation($sqlOperation)
            ->setValues($values);

        /** @var Tagger $tagger */
        $tagger = app(Tagger::class, [$reflector]);

        return $tagger->getTags();
    }
}