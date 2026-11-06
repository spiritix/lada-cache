<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use InvalidArgumentException;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Generation;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class CacheTest extends TestCase
{
    private const string GENERATION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string GENERATION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Redis $redis;
    private Encoder $encoder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
        $this->encoder = new Encoder();
    }

    public function testCurrentGenerationIsCreatedOnceAndStaysStable(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);

        $first = $cache->currentGeneration();

        $this->assertSame($first->id, $cache->currentGeneration()->id);
        $this->assertSame($first->id, $this->redis->get('p:generation'));
    }

    public function testCurrentGenerationAdoptsTheStoredId(): void
    {
        $this->redis->set('p:generation', self::GENERATION_A);
        $cache = new Cache($this->redis, $this->encoder, 0);

        $this->assertSame(self::GENERATION_A, $cache->currentGeneration()->id);
    }

    public function testCorruptStoredGenerationFailsLoudly(): void
    {
        $this->redis->set('p:generation', '7');
        $cache = new Cache($this->redis, $this->encoder, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Lada Cache generation id "7"');

        $cache->currentGeneration();
    }

    public function testSetStoresValueWithPrefixAndTagsWithExpiration(): void
    {
        $tags = ['t1', 't2'];
        $value = ['a' => 1];
        $encoded = $this->encoder->encode($value);
        $storedKey = 'p:'.self::GENERATION_A.':k';

        $cache = new Cache($this->redis, $this->encoder, 60);
        $cache->set(new Generation(self::GENERATION_A), 'k', $tags, $value);

        $this->assertSame($encoded, $this->redis->get($storedKey));
        $this->assertGreaterThan(0, (int) $this->redis->ttl($storedKey));
        $this->assertSame(1, (int) $this->redis->sismember('p:'.self::GENERATION_A.':t1', $storedKey));
        $this->assertSame(1, (int) $this->redis->sismember('p:'.self::GENERATION_A.':t2', $storedKey));
    }

    public function testSetStoresValueWithoutExpirationWhenZero(): void
    {
        $value = 'string-value';
        $encoded = $this->encoder->encode($value);
        $storedKey = 'p:'.self::GENERATION_A.':k2';

        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->set(new Generation(self::GENERATION_A), 'k2', ['t'], $value);

        $this->assertSame($encoded, $this->redis->get($storedKey));
        $this->assertSame(-1, (int) $this->redis->ttl($storedKey));
        $this->assertSame(1, (int) $this->redis->sismember('p:'.self::GENERATION_A.':t', $storedKey));
    }

    public function testGetReturnsNullWhenKeyMissing(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);

        $this->assertNull($cache->get(new Generation(self::GENERATION_A), 'missing'));
    }

    public function testGetDecodesEncodedPayload(): void
    {
        $original = ['x' => 10, 'y' => [1, 2]];
        $this->redis->set('p:'.self::GENERATION_A.':payload', $this->encoder->encode($original));

        $cache = new Cache($this->redis, $this->encoder, 0);

        $this->assertSame($original, $cache->get(new Generation(self::GENERATION_A), 'payload'));
    }

    public function testGetDoesNotReadEntriesOfAnotherGeneration(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->set(new Generation(self::GENERATION_A), 'k', [], ['stale']);

        $this->assertNull($cache->get(new Generation(self::GENERATION_B), 'k'));
    }

    public function testFlushScansAndDeletesAllPrefixedKeys(): void
    {
        $this->redis->set('p:k1', '1');
        $this->redis->set('p:k2', '1');
        $this->redis->set('p:k3', '1');

        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->flush();

        $this->assertSame(0, (int) $this->redis->exists('p:k1'));
        $this->assertSame(0, (int) $this->redis->exists('p:k2'));
        $this->assertSame(0, (int) $this->redis->exists('p:k3'));
    }

    public function testFlushAdvancesGenerationSoPreviousEntriesAreNeverServed(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);
        $before = $cache->currentGeneration();
        $cache->set($before, 'k', ['t'], ['stale']);

        $cache->flush();
        $after = $cache->currentGeneration();

        $this->assertNotSame($before->id, $after->id);
        $this->assertNull($cache->get($after, 'k'));
        $this->assertSame(0, (int) $this->redis->exists('p:'.$before->scope('k')));
        $this->assertSame(0, (int) $this->redis->exists('p:'.$before->scope('t')));
    }

    public function testRetireMakesEntriesUnreachableWithoutDeletingThem(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);
        $before = $cache->currentGeneration();
        $cache->set($before, 'k', ['t'], ['stale']);

        $cache->retireCurrentGeneration();

        $this->assertNull($cache->get($cache->currentGeneration(), 'k'));
        $this->assertSame(1, (int) $this->redis->exists('p:'.$before->scope('k')));
    }

    public function testPruneKeepsGenerationKeyAndCurrentEntriesAndDeletesTheRest(): void
    {
        $this->redis->set('p:generation', self::GENERATION_B);
        $this->redis->set('p:'.self::GENERATION_A.':k', $this->encoder->encode(['retired']));
        $this->redis->set('p:'.self::GENERATION_B.':k', $this->encoder->encode(['current']));
        $this->redis->sadd('p:'.self::GENERATION_B.':t', 'p:'.self::GENERATION_B.':k');
        $this->redis->set('p:legacy-key', '1');
        $cache = new Cache($this->redis, $this->encoder, 0);

        $cache->pruneRetiredGenerations();

        $this->assertSame(self::GENERATION_B, $this->redis->get('p:generation'));
        $this->assertSame(['current'], $cache->get(new Generation(self::GENERATION_B), 'k'));
        $this->assertSame(1, (int) $this->redis->sismember('p:'.self::GENERATION_B.':t', 'p:'.self::GENERATION_B.':k'));
        $this->assertSame(0, (int) $this->redis->exists('p:'.self::GENERATION_A.':k'));
        $this->assertSame(0, (int) $this->redis->exists('p:legacy-key'));
    }

    public function testEntryWrittenWithRetiredGenerationAfterFlushIsUnreachable(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);
        $capturedBeforeMigration = $cache->currentGeneration();

        $cache->flush();
        $cache->set($capturedBeforeMigration, 'k', ['t'], ['pre-migration-schema']);

        $this->assertNull($cache->get($cache->currentGeneration(), 'k'));
    }

    public function testEmptiedRedisNeverRevivesEntriesWrittenWithAnEarlierGeneration(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 0);
        $capturedBeforeWipe = $cache->currentGeneration();

        $this->redis->flushdb();
        $afterWipe = $cache->currentGeneration();
        $cache->set($capturedBeforeWipe, 'k', ['t'], ['written-by-pod-mid-query']);
        $cache->retireCurrentGeneration();
        $cache->retireCurrentGeneration();

        $this->assertNotSame($capturedBeforeWipe->id, $afterWipe->id);
        $this->assertNull($cache->get($afterWipe, 'k'));
        $this->assertNull($cache->get($cache->currentGeneration(), 'k'));
    }
}
