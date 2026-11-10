<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class ManagerTest extends TestCase
{
    private function makeReflectorFromTables(array $tables): Reflector
    {
        // Use first table as FROM and join remaining tables (no execution occurs)
        $first = array_shift($tables) ?? 'cars';
        $builder = DB::table($first);
        foreach ($tables as $t) {
            $builder->join($t, "$first.id", '=', "$t.id");
        }

        return new Reflector($builder);
    }

    public function testShouldCacheTrueWhenActiveAndTablesAllowed(): void
    {
        config([
            'lada-cache.active' => true,
            'lada-cache.include_tables' => [],
            'lada-cache.exclude_tables' => [],
        ]);

        $manager = new Manager($this->makeReflectorFromTables(['cars']));
        $this->assertTrue($manager->shouldCache());
    }

    public function testIncludeTablesRequiresAllTablesToBeIncluded(): void
    {
        config([
            'lada-cache.active' => true,
            'lada-cache.include_tables' => ['cars', 'drivers'],
            'lada-cache.exclude_tables' => [],
        ]);

        $managerAllIncluded = new Manager($this->makeReflectorFromTables(['cars', 'drivers']));
        $this->assertTrue($managerAllIncluded->shouldCache());

        $managerPartial = new Manager($this->makeReflectorFromTables(['cars', 'engines']));
        $this->assertFalse($managerPartial->shouldCache());
    }

    public function testExcludeTablesBlocksWhenAnyTableIsExcluded(): void
    {
        config([
            'lada-cache.active' => true,
            'lada-cache.include_tables' => [],
            'lada-cache.exclude_tables' => ['drivers'],
        ]);

        $managerAllowed = new Manager($this->makeReflectorFromTables(['cars']));
        $this->assertTrue($managerAllowed->shouldCache());

        $managerBlocked = new Manager($this->makeReflectorFromTables(['cars', 'drivers']));
        $this->assertFalse($managerBlocked->shouldCache());
    }

    public function testInactiveDisablesCachingRegardlessOfTables(): void
    {
        // Temporarily enable to get a Lada query builder, then test Manager with inactive config
        config(['lada-cache.active' => true]);
        $reflector = $this->makeReflectorFromTables(['cars']);

        // Now set inactive and test that Manager respects it
        config([
            'lada-cache.active' => false,
            'lada-cache.include_tables' => ['cars'],
            'lada-cache.exclude_tables' => [],
        ]);

        $manager = new Manager($reflector);
        $this->assertFalse($manager->shouldCache());
    }

    public function testModelsScopeCachesOnlyModelQueries(): void
    {
        config(['lada-cache.scope' => 'models']);

        $this->assertFalse((new Manager($this->makeReflectorFromTables(['cars'])))->shouldCache());
        $this->assertTrue((new Manager(new Reflector(Car::query()->getQuery())))->shouldCache());
    }

    public function testModelsScopeStillInvalidatesQueryBuilderWrites(): void
    {
        config(['lada-cache.scope' => 'models']);

        $this->assertTrue((new Manager($this->makeReflectorFromTables(['cars'])))->shouldInvalidate());
    }

    public function testAllScopeCachesQueryBuilderQueries(): void
    {
        config(['lada-cache.scope' => 'all']);

        $this->assertTrue((new Manager($this->makeReflectorFromTables(['cars'])))->shouldCache());
    }

    public function testInvalidScopeFailsLoudly(): void
    {
        config(['lada-cache.scope' => 'everything']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lada-cache.scope "everything", expected one of: all, models');

        new Manager($this->makeReflectorFromTables(['cars']));
    }
}
