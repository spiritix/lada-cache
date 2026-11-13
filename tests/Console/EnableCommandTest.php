<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class EnableCommandTest extends TestCase
{
    public function testEnableCommandPrintsMessagesAndSucceeds(): void
    {
        $this->artisan('lada-cache:enable')
            ->expectsOutput('Lada Cache enabled.')
            ->assertExitCode(0);
    }

    public function testEnablingFlushesResultsCachedBeforeWritesWentUntracked(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before']);
        $this->assertSame('before', Car::query()->find(1)?->name);

        config(['lada-cache.active' => false]);
        Car::query()->whereKey(1)->update(['name' => 'written while disabled']);

        $this->artisan('lada-cache:enable')->assertExitCode(0);
        config(['lada-cache.active' => true]);

        $this->assertSame('written while disabled', Car::query()->find(1)?->name);
    }

    public function testEnablingFailsWithoutEnablingWhenStaleResultsCannotBeFlushed(): void
    {
        config([
            'database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2],
            'lada-cache.redis_connection' => 'unreachable',
        ]);
        $this->app->forgetInstance('redis');
        Facade::clearResolvedInstance('redis');

        $this->artisan('lada-cache:enable')
            ->expectsOutputToContain('Lada Cache was not enabled: could not flush results cached before it was disabled: Connection refused')
            ->doesntExpectOutput('Lada Cache enabled.')
            ->assertExitCode(1);
    }
}
