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

    public function testFlushDeletesAllEntriesAndKeepsOnlyTheGenerationCounter(): void
    {
        RedisFacade::purge('cache');

        $redis = new Redis();
        $encoder = new Encoder();
        $cache = new Cache($redis, $encoder, 0);

        $cache->set($cache->currentGeneration(), 'k1', ['t1'], '1');
        $cache->set($cache->currentGeneration(), 'k2', ['t2'], '2');

        $keysBefore = RedisFacade::connection('cache')->keys('*lada:*');
        $this->assertGreaterThanOrEqual(4, count($keysBefore));

        $cache->flush();

        $keysAfter = RedisFacade::connection('cache')->keys('*lada:*');
        $this->assertSame(['app_lada:generation'], $keysAfter);
    }
}
