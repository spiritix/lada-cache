<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

final class ModelsScopeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('lada-cache.scope', 'models');
    }

    public function testReadsFromModelsWithTraitAreCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'cached', 'engine_id' => null, 'driver_id' => null]);

        $executed = $this->countExecutedSelects(function (): void {
            Car::query()->where('id', 1)->get();
            Car::query()->where('id', 1)->get();
        });

        $this->assertSame(1, $executed);
    }

    public function testReadsFromQueryBuilderAreNotCached(): void
    {
        DB::table('cars')->insert(['id' => 2, 'name' => 'raw', 'engine_id' => null, 'driver_id' => null]);

        $executed = $this->countExecutedSelects(function (): void {
            DB::table('cars')->where('id', 2)->get();
            DB::table('cars')->where('id', 2)->get();
        });

        $this->assertSame(2, $executed);
    }

    public function testReadsFromModelsWithoutTraitAreNotCached(): void
    {
        DB::table('drivers')->insert(['id' => 3, 'name' => 'untraited']);

        $executed = $this->countExecutedSelects(function (): void {
            DriverWithoutLadaCache::query()->where('id', 3)->get();
            DriverWithoutLadaCache::query()->where('id', 3)->get();
        });

        $this->assertSame(2, $executed);
    }

    public function testQueryBuilderWritesInvalidateCachedModelReads(): void
    {
        DB::table('cars')->insert(['id' => 4, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('before', Car::query()->find(4)?->name);

        DB::table('cars')->where('id', 4)->update(['name' => 'after']);

        $this->assertSame('after', Car::query()->find(4)?->name);
    }

    public function testWritesFromModelsWithoutTraitInvalidateCachedModelReads(): void
    {
        DB::table('drivers')->insert(['id' => 5, 'name' => 'before']);
        $this->assertSame('before', Driver::query()->find(5)?->name);

        DriverWithoutLadaCache::query()->whereKey(5)->update(['name' => 'after']);

        $this->assertSame('after', Driver::query()->find(5)?->name);
    }

    private function countExecutedSelects(Closure $queries): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $queries();
        DB::disableQueryLog();

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'),
        ));
    }
}

final class DriverWithoutLadaCache extends Model
{
    protected $table = 'drivers';

    public $timestamps = false;

    protected $guarded = [];
}
