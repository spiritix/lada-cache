<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Metrics;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

final class MetricsTest extends TestCase
{
    use RecordsRedisCommands;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('lada-cache.metrics', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->exec("insert into owners (id, name) values (1, 'alice'), (2, 'bob')");
        DB::connection()->getPdo()->exec("insert into pets (id, owner_id, name) values (1, 1, 'rex')");
    }

    public function testAReadCountsAMissAndAStoreAndThenHits(): void
    {
        DB::table('owners')->get();
        DB::table('owners')->get();
        DB::table('owners')->get();

        $this->assertSame(['hit:owners' => 2, 'miss:owners' => 1, 'store:owners' => 1], $this->counters());
    }

    public function testAWriteCountsItsInvalidationAndTheTablesItsCascadesReach(): void
    {
        $this->loadDependencyMap();

        DB::table('owners')->where('id', 1)->delete();

        $counters = $this->counters();
        $this->assertSame(1, $counters['inval:owners']);
        $this->assertSame(5, $counters['cascade:owners'], 'Two views, the toys of the deleted pets and the two tables referencing the owner');
        $this->assertArrayNotHasKey('inval:pets', $counters);
    }

    public function testTheWriteThatLoadsTheDependencyMapIsCountedOnce(): void
    {
        DB::table('owners')->get();

        DB::table('owners')->where('id', 2)->update(['name' => 'renamed']);

        $this->assertSame(1, $this->counters()['inval:owners']);
    }

    public function testRetiresAreCountedByReasonAndLeaveTheCountersInPlace(): void
    {
        DB::table('owners')->get();

        Artisan::call('lada-cache:flush');
        DB::statement('create table metrics_probe (id integer)');
        Services::metrics()->flush(Services::redis());

        $counters = $this->counters();
        $this->assertSame(1, $counters['retire:flush']);
        $this->assertSame(1, $counters['retire:statement']);
        $this->assertSame(1, $counters['raw_everything:create table metrics_probe (id integer)']);
        $this->assertSame(1, $counters['miss:owners'], 'A retire and its prune keep the counters');
    }

    public function testCountingAddsNoRedisCallToAReadOrAWrite(): void
    {
        $this->loadDependencyMap();
        DB::table('owners')->get();

        $read = $this->recordRedisCommands(fn () => DB::table('owners')->get());
        $write = $this->recordRedisCommands(fn () => DB::table('owners')->where('id', 2)->update(['name' => 'renamed']));

        $this->assertSame(['evalsha'], $read);
        $this->assertSame(['evalsha'], $write);
    }

    public function testWhatOnlyPhpSeesIsWrittenInOneCallWhenTheRequestEnds(): void
    {
        DB::table('owners')->whereRaw('current_timestamp is not null')->get();
        DB::table('owners')->whereRaw('current_timestamp is not null')->get();

        $first = $this->recordRedisCommands(fn () => $this->app->terminate());
        $second = $this->recordRedisCommands(fn () => $this->app->terminate());

        $this->assertSame(['evalsha'], $first);
        $this->assertSame([], $second, 'Nothing was counted since');
        $this->assertSame(2, $this->counters()['bypass:non_deterministic']);
    }

    public function testALongLivedWorkerWritesItsCountsOnlyEveryFewSeconds(): void
    {
        DB::table('owners')->whereRaw('current_timestamp is not null')->get();
        $this->app->terminate();
        DB::table('owners')->whereRaw('current_timestamp is not null')->get();

        $nextRequest = $this->recordRedisCommands(fn () => $this->app->terminate());

        $this->assertSame([], $nextRequest);
        $this->assertSame(1, $this->counters()['bypass:non_deterministic']);
    }

    public function testBucketsExpireAfterTheRetention(): void
    {
        DB::table('owners')->get();
        $redis = Services::redis();
        $buckets = $redis->getConnection()->command('keys', [$redis->prefix(Metrics::BUCKET_PREFIX.'*')]);

        $this->assertIsArray($buckets);
        $this->assertCount(1, $buckets);
        $ttl = $redis->getConnection()->command('ttl', [$buckets[0]]);
        $this->assertGreaterThan(Metrics::DEFAULT_RETENTION_SECONDS - 60, $ttl);
        $this->assertLessThanOrEqual(Metrics::DEFAULT_RETENTION_SECONDS, $ttl);
    }

    public function testTheStatsCommandReportsPerTableAndInTotal(): void
    {
        $this->loadDependencyMap();
        DB::table('owners')->get();
        DB::table('owners')->get();
        DB::table('owners')->where('id', 1)->delete();
        Artisan::call('lada-cache:flush');

        Artisan::call('lada-cache:stats', ['--json' => true, '--table' => 'owners']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['owners'], array_keys($report['tables']));
        $this->assertSame(
            ['hits' => 1, 'misses' => 1, 'hit_rate' => 0.5, 'stores' => 1, 'stores_per_hit' => 1, 'skipped_for_size' => 0, 'invalidations' => 1, 'cascade_reach' => 5],
            $report['tables']['owners'],
        );
        $this->assertSame(['flush' => 1], $report['retires']);
        $this->artisan('lada-cache:stats', ['--hours' => '2'])->expectsOutputToContain('owners')->expectsOutputToContain('Retires: flush x1')->assertExitCode(0);
    }

    /**
     * @return array<string, int>
     */
    private function counters(): array
    {
        return Services::metrics()->read(Services::redis(), 1);
    }
}
