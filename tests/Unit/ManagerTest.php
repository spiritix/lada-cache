<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\TestCase;

class ManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
    }

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
        config([
            'lada-cache.active' => false,
            'lada-cache.include_tables' => ['cars'],
            'lada-cache.exclude_tables' => [],
        ]);

        $manager = new Manager($this->makeReflectorFromTables(['cars']));
        $this->assertFalse($manager->shouldCache());
    }
}
