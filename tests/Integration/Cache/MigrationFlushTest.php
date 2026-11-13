<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class MigrationFlushTest extends TestCase
{
    public function testMigrationsFlushTheCache(): void
    {
        $redis = new Redis;
        $redis->set($redis->prefix('k1'), '1');

        Event::dispatch(new MigrationsEnded('up'));

        $this->assertSame(0, (int) $redis->exists($redis->prefix('k1')));
    }

    public function testUnreachableRedisDoesNotFailMigrations(): void
    {
        $this->useUnreachableRedis();
        Log::spy();

        Event::dispatch(new MigrationsEnded('up'));

        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'Redis flush after migrations failed'));
    }
}
