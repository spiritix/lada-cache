<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class FlushWithConnectionPrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Enable connection-level prefix to reproduce the bug
        $app['config']->set('database.redis.options.prefix', 'app_');
        $app['config']->set('lada-cache.prefix', 'lada:');
    }

    public function testFlushDeletesAllKeys(): void
    {
        RedisFacade::purge('cache');

        $redis = new Redis();
        $encoder = new Encoder();
        $cache = new Cache($redis, $encoder, 0);

        $cache->set('k1', [], '1');
        $cache->set('k2', [], '2');

        $keysBefore = RedisFacade::connection('cache')->keys('*lada:*');
        $this->assertGreaterThanOrEqual(2, count($keysBefore));

        $cache->flush();

        $keysAfter = RedisFacade::connection('cache')->keys('*lada:*');
        $this->assertEmpty($keysAfter, 'flush() should delete all keys');
    }
}
