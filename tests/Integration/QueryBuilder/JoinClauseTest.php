<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Test join clauses with nested where conditions.
 *
 * Regression test for issue #142: nested where() calls inside join closures
 * were causing type errors due to incorrect QueryBuilder instantiation.
 */
class JoinClauseTest extends TestCase
{
    use CacheAssertions;
    use MakesTestModels;

    protected function seedData(): array
    {
        $d1 = Driver::factory()->create(['id' => 27, 'name' => 'Alice']);
        $d2 = Driver::factory()->create(['id' => 42, 'name' => 'Bob']);

        $c1 = Car::create(['name' => 'Car A', 'driver_id' => $d1->id]);
        $c2 = Car::create(['name' => 'Car B', 'driver_id' => $d1->id]);
        $c3 = Car::create(['name' => 'Car C', 'driver_id' => $d2->id]);

        return compact('d1', 'd2', 'c1', 'c2', 'c3');
    }

    public function test_join_with_nested_where_closure_works(): void
    {
        $data = $this->seedData();

        // Regression test for issue #142: nested where in join closure
        // Previously failed with: QueryBuilder::__construct(): Argument #2 ($handler)
        // must be of type QueryHandler, MySqlGrammar given
        $results = DB::table('cars')
            ->join('drivers', static function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->where(function ($q): void {
                        $q->where('drivers.id', '=', 27);
                    });
            })
            ->get();

        Assert::assertCount(2, $results, 'Expected 2 cars belonging to driver 27');

        // Verify the query is cached on second run
        $results2 = DB::table('cars')
            ->join('drivers', static function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->where(function ($q): void {
                        $q->where('drivers.id', '=', 27);
                    });
            })
            ->get();

        Assert::assertSame($results->toJson(), $results2->toJson());
    }

    public function test_join_with_multiple_nested_where_closures(): void
    {
        $data = $this->seedData();

        // More complex nesting scenario
        $results = DB::table('cars')
            ->join('drivers', static function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->where(function ($q): void {
                        $q->where('drivers.id', '>=', 20)
                            ->where(function ($nested): void {
                                $nested->where('drivers.id', '<=', 30)
                                    ->orWhere('drivers.id', '=', 42);
                            });
                    });
            })
            ->orderBy('cars.id')
            ->get();

        Assert::assertGreaterThan(0, $results->count());

        // Verify cached
        $results2 = DB::table('cars')
            ->join('drivers', static function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->where(function ($q): void {
                        $q->where('drivers.id', '>=', 20)
                            ->where(function ($nested): void {
                                $nested->where('drivers.id', '<=', 30)
                                    ->orWhere('drivers.id', '=', 42);
                            });
                    });
            })
            ->orderBy('cars.id')
            ->get();

        Assert::assertSame($results->toJson(), $results2->toJson());
    }

    public function test_join_with_nested_where_and_orWhere(): void
    {
        $data = $this->seedData();

        $results = DB::table('cars')
            ->join('drivers', static function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->where(function ($q): void {
                        $q->where('drivers.name', 'Alice')
                            ->orWhere('drivers.name', 'Bob');
                    });
            })
            ->get();

        Assert::assertGreaterThan(0, $results->count());
    }
}
