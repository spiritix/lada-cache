<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Metrics;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

final class StatsCommandTest extends TestCase
{
    use RecordsRedisCommands;

    public function testSaysWhenMetricsAreOff(): void
    {
        $this->artisan('lada-cache:stats')
            ->expectsOutput('Lada Cache metrics are off. Set LADA_CACHE_METRICS=true to collect them.')
            ->assertExitCode(0);
    }

    public function testNothingIsCountedOrWrittenWhileMetricsAreOff(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->get();
        DB::table('cars')->whereRaw('current_timestamp is not null')->get();

        $hit = $this->recordRedisCommands(fn () => DB::table('cars')->get());
        $requestEnd = $this->recordRedisCommands(fn () => $this->app->terminate());
        $redis = Services::redis();

        $this->assertSame(['evalsha'], $hit);
        $this->assertSame([], $requestEnd);
        $this->assertSame([], $redis->getConnection()->command('keys', [$redis->prefix(Metrics::BUCKET_PREFIX.'*')]));
    }
}
