<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use InvalidArgumentException;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheHit;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\SchemaVersion;
use Spiritix\LadaCache\Tests\TestCase;

class CacheTest extends TestCase
{
    private Redis $redis;

    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
        $this->cache = new Cache($this->redis, new Encoder(), new ExpirationPolicy(0));
    }

    public function testFirstLookupCreatesAStableGeneration(): void
    {
        $first = $this->miss('k', ['t']);
        $second = $this->miss('k', ['t']);

        $this->assertSame($first->generation->id, $second->generation->id);
        $this->assertSame($first->generation->id, $this->redis->get('p:generation'));
    }

    public function testStoredEntryIsServedUntilOneOfItsTagsIsInvalidated(): void
    {
        $this->cache->store($this->miss('k', ['t1', 't2']), ['row']);

        $hit = $this->cache->lookup('k', ['t1', 't2'], SchemaVersion::fixed('test', '1'));
        $this->cache->invalidate(['unrelated']);
        $stillHit = $this->cache->lookup('k', ['t1', 't2'], SchemaVersion::fixed('test', '1'));
        $this->cache->invalidate(['t2']);

        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['row'], $hit->rows);
        $this->assertInstanceOf(CacheHit::class, $stillHit);
        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t1', 't2'], SchemaVersion::fixed('test', '1')));
    }

    public function testTagOrderAndDuplicatesDoNotChangeTheFingerprint(): void
    {
        $this->cache->store($this->miss('k', ['b', 'a']), ['row']);

        $this->assertInstanceOf(CacheHit::class, $this->cache->lookup('k', ['a', 'b', 'a'], SchemaVersion::fixed('test', '1')));
    }

    public function testStoreAfterConcurrentInvalidationIsNeverServed(): void
    {
        $capturedBeforeWrite = $this->miss('k', ['t']);

        $this->cache->invalidate(['t']);
        $this->cache->store($capturedBeforeWrite, ['stale']);

        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
    }

    public function testStoreAfterRetireIsDiscarded(): void
    {
        $capturedBeforeMigration = $this->miss('k', ['t']);

        $this->cache->retireCurrentGeneration();
        $this->cache->store($capturedBeforeMigration, ['pre-migration-schema']);

        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
        $this->assertSame(0, (int) $this->redis->exists('p:'.$capturedBeforeMigration->generation->id.':e:1:k'));
    }

    public function testEntryWithoutValidFingerprintIsAMiss(): void
    {
        $miss = $this->miss('k', ['t']);
        $this->redis->set('p:'.$miss->generation->id.':e:1:k', 'legacy-unfingerprinted-payload');

        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
    }

    public function testEmptiedRedisNeverRevivesEntriesWrittenWithAnEarlierGeneration(): void
    {
        $capturedBeforeWipe = $this->miss('k', ['t']);

        $this->redis->flushdb();
        $afterWipe = $this->miss('k', ['t']);
        $this->cache->store($capturedBeforeWipe, ['written-by-pod-mid-query']);

        $this->assertNotSame($capturedBeforeWipe->generation->id, $afterWipe->generation->id);
        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
    }

    public function testCorruptStoredGenerationFailsLoudly(): void
    {
        $this->redis->set('p:generation', '7');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Lada Cache generation id "7"');

        $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1'));
    }

    public function testRetireMakesEntriesUnreachable(): void
    {
        $this->cache->store($this->miss('k', ['t']), ['row']);

        $this->cache->retireCurrentGeneration();

        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
    }

    public function testPruneKeepsCurrentEntriesAndDeletesEverythingElse(): void
    {
        $retired = $this->miss('old', ['t']);
        $this->cache->store($retired, ['retired']);
        $this->cache->retireCurrentGeneration();
        $this->cache->store($this->miss('k', ['t']), ['current']);
        $this->redis->set('p:legacy-key', '1');

        $deleted = $this->cache->pruneRetiredGenerations();

        $this->assertSame(3, $deleted);
        $this->assertSame(0, (int) $this->redis->exists('p:'.$retired->generation->id.':e:1:old'));
        $this->assertSame(0, (int) $this->redis->exists('p:legacy-key'));
        $hit = $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1'));
        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['current'], $hit->rows);
    }

    public function testPruneWalksPastASingleScanBatch(): void
    {
        $retired = $this->miss('k', ['t']);
        $this->cache->retireCurrentGeneration();
        for ($index = 0; $index < 2500; $index++) {
            $this->redis->set('p:'.$retired->generation->id.':e:1:'.$index, '1');
        }

        $this->cache->pruneRetiredGenerations();

        $this->assertSame([], $this->redis->keys('p:'.$retired->generation->id.':*'));
    }

    public function testPruneTreatsGlobCharactersInThePrefixLiterally(): void
    {
        config(['lada-cache.prefix' => 'g[1]*:']);
        $redis = new Redis();
        $cache = new Cache($redis, new Encoder(), new ExpirationPolicy(0));
        $cache->retireCurrentGeneration();
        $redis->set('g1:foreign-application-key', 'keep');
        $redis->set('g[1]*:legacy-key', 'drop');

        $cache->pruneRetiredGenerations();

        $this->assertSame('keep', $redis->get('g1:foreign-application-key'));
        $this->assertSame(0, (int) $redis->exists('g[1]*:legacy-key'));
    }

    public function testFlushRetiresAndPrunes(): void
    {
        $before = $this->miss('k', ['t']);
        $this->cache->store($before, ['stale']);

        $this->cache->flush();

        $this->assertInstanceOf(CacheMiss::class, $this->cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1')));
        $this->assertSame([], $this->redis->keys('p:'.$before->generation->id.':*'));
    }

    public function testEntriesAndVersionsExpireWithTheConfiguredTtl(): void
    {
        $cache = new Cache($this->redis, new Encoder(), new ExpirationPolicy(60));
        $miss = $cache->lookup('k', ['t'], SchemaVersion::fixed('test', '1'));
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['row']);

        $this->assertGreaterThan(0, (int) $this->redis->ttl('p:'.$miss->generation->id.':e:1:k'));
        $this->assertGreaterThan(0, (int) $this->redis->ttl('p:'.$miss->generation->id.':v:t'));
    }

    public function testZeroTtlKeepsEntriesWithoutExpiry(): void
    {
        $miss = $this->miss('k', ['t']);
        $this->cache->store($miss, ['row']);

        $this->assertSame(-1, (int) $this->redis->ttl('p:'.$miss->generation->id.':e:1:k'));
    }

    /**
     * @param  array<string>  $tags
     */
    private function miss(string $key, array $tags): CacheMiss
    {
        $lookup = $this->cache->lookup($key, $tags, SchemaVersion::fixed('test', '1'));
        $this->assertInstanceOf(CacheMiss::class, $lookup);

        return $lookup;
    }
}
