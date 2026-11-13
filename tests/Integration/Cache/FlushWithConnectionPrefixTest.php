<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheHit;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Tests\TestCase;

class FlushWithConnectionPrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.options.prefix', 'app_');
        $app['config']->set('lada-cache.prefix', 'lada:');
    }

    public function testEntriesRoundTripUnderConnectionPrefix(): void
    {
        $cache = $this->freshCache();

        $miss = $cache->lookup('k1', ['t1'], []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['row']);

        $hit = $cache->lookup('k1', ['t1'], []);
        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['row'], $hit->rows);
        $this->assertContains('app_lada:generation', RedisFacade::connection('cache')->keys('lada:*'));
    }

    public function testInvalidationHonoursConnectionPrefix(): void
    {
        $cache = $this->freshCache();
        $this->seedEntry($cache, 'k1', ['t1'], ['row']);

        $cache->invalidate(Invalidation::of(['t1']));

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k1', ['t1'], []));
    }

    public function testFlushDeletesAllEntriesAndKeepsOnlyTheGenerationAndServerKeys(): void
    {
        $cache = $this->freshCache();
        $this->seedEntry($cache, 'k1', ['t1'], ['1']);
        $this->seedEntry($cache, 'k2', ['t2'], ['2']);
        $this->assertGreaterThanOrEqual(5, count(RedisFacade::connection('cache')->keys('lada:*')));

        $cache->flush();

        $remaining = RedisFacade::connection('cache')->keys('lada:*');
        sort($remaining);
        $this->assertSame(['app_lada:generation', 'app_lada:server'], $remaining);
    }

    public function testLegacyInvalidationDeletesEarlierVersionsEntriesUnderConnectionPrefix(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $cache = $this->freshCache();
        $client = RedisFacade::connection('cache');
        $client->command('sadd', ['lada:tags:database:db:table_unspecific:cars', 'lada:legacy-hash']);
        $client->command('set', ['lada:legacy-hash', 'serialized rows']);

        $cache->invalidate(Invalidation::of(['tags:database:db:table_unspecific:cars']));

        $this->assertSame([], $client->keys('lada:tags:*'));
        $this->assertSame([], $client->keys('lada:legacy-hash'));
    }

    public function testEarlierVersionsInvalidationsReachThisVersionsEntriesUnderConnectionPrefix(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $cache = $this->freshCache();
        $client = RedisFacade::connection('cache');
        $this->seedEntry($cache, 'k1', ['tags:database:db:table_unspecific:cars'], ['row']);
        $members = $client->command('smembers', ['lada:tags:database:db:table_unspecific:cars']);
        $this->assertSame(['lada:legacy:tags:database:db:table_unspecific:cars'], $members);

        $client->command('del', ['lada:tags:database:db:table_unspecific:cars']);
        foreach ($members as $member) {
            $client->command('del', [$member]);
        }

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k1', ['tags:database:db:table_unspecific:cars'], []));
    }

    private function freshCache(): Cache
    {
        RedisFacade::purge('cache');

        return new Cache(new Redis, new Encoder, new ExpirationPolicy(0));
    }

    /**
     * @param  array<string>  $tags
     */
    private function seedEntry(Cache $cache, string $key, array $tags, array $rows): void
    {
        $miss = $cache->lookup($key, $tags, []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, $rows);
    }
}
