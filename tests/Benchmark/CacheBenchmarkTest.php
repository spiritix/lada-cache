<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Measures what a cached read costs an application: reads per second with and without the cache,
 * and the Redis round trips a cached read takes. Run it explicitly, it is not part of a suite.
 *
 * The read scans a table by a leading-wildcard LIKE, which is the shape a cache helps with.
 */
final class CacheBenchmarkTest extends TestCase
{
    use RecordsRedisCommands;

    private const int ROWS = 2000;

    private const int READS = 2000;

    public function testReportCachedReadThroughput(): void
    {
        for ($batch = 0; $batch < self::ROWS / 100; $batch++) {
            $rows = [];
            for ($index = 1; $index <= 100; $index++) {
                $rows[] = ['name' => 'car '.($batch * 100 + $index), 'engine_id' => null, 'driver_id' => null];
            }
            BenchmarkCar::query()->insert($rows);
        }

        $uncached = $this->measure(fn () => config(['lada-cache.active' => false]));
        $hits = $this->measure();
        $roundTrips = count($this->recordRedisCommands(fn () => $this->measure()));

        fwrite(STDERR, sprintf(
            "\nuncached: %9.0f reads/s\ncached: %9.0f reads/s\ncached to uncached: %9.1fx\nredis commands per %d cached reads: %d\n",
            $uncached,
            $hits,
            $hits / $uncached,
            self::READS,
            $roundTrips,
        ));

        $this->assertGreaterThan($uncached, $hits);
        $this->assertSame(self::READS, $roundTrips);
    }

    /**
     * @param  (\Closure(): mixed)|null  $setUp  Changes the configuration for the measurement only
     */
    private function measure(?\Closure $setUp = null): float
    {
        $wasActive = config('lada-cache.active');
        $setUp?->__invoke();
        $started = hrtime(true);

        for ($index = 1; $index <= self::READS; $index++) {
            BenchmarkCar::query()->where('name', 'like', '%'.($index % 50))->orderBy('name')->limit(10)->get();
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        config(['lada-cache.active' => $wasActive]);

        return self::READS / $elapsed;
    }
}

final class BenchmarkCar extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $table = 'cars';

    protected $fillable = ['name', 'engine_id', 'driver_id'];
}
