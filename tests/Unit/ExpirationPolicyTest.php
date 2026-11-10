<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use InvalidArgumentException;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

final class ExpirationPolicyTest extends TestCase
{
    public function testWithoutJitterEveryTtlIsTheConfiguredTime(): void
    {
        $policy = new ExpirationPolicy(3600);

        $this->assertSame([3600], array_values(array_unique($this->sampleTtls($policy))));
    }

    public function testJitterSpreadsTtlsWithinTheConfiguredPercentage(): void
    {
        $ttls = $this->sampleTtls(new ExpirationPolicy(1000, 15));

        $this->assertGreaterThanOrEqual(850, min($ttls));
        $this->assertLessThanOrEqual(1150, max($ttls));
        $this->assertGreaterThan(50, count(array_unique($ttls)));
    }

    public function testZeroExpirationStaysPersistentRegardlessOfJitter(): void
    {
        $this->assertSame([0], array_values(array_unique($this->sampleTtls(new ExpirationPolicy(0, 50)))));
    }

    public function testFullJitterNeverProducesAPersistentTtl(): void
    {
        $this->assertGreaterThanOrEqual(1, min($this->sampleTtls(new ExpirationPolicy(1, 100))));
    }

    public function testOutOfRangeJitterFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lada-cache.expiration_jitter 101');

        new ExpirationPolicy(60, 101);
    }

    public function testNonIntegerConfigFailsLoudly(): void
    {
        config(['lada-cache.expiration_jitter' => 'lots']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lada-cache.expiration_jitter "lots", expected an integer');

        ExpirationPolicy::fromConfig();
    }

    public function testStoredEntriesAndVersionsGetJitteredTtls(): void
    {
        config(['lada-cache.prefix' => 'jitter:']);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder, new ExpirationPolicy(10_000, 50));
        $ttls = [];

        for ($entry = 0; $entry < 20; $entry++) {
            $miss = $cache->lookup("k{$entry}", ["t{$entry}"], []);
            $this->assertInstanceOf(CacheMiss::class, $miss);
            $cache->store($miss, ['row']);
            $ttls[] = (int) $redis->ttl("jitter:{$miss->generation->id}:e:k{$entry}");
            $ttls[] = (int) $redis->ttl("jitter:{$miss->generation->id}:v:t{$entry}");
        }

        $this->assertGreaterThanOrEqual(4_999, min($ttls));
        $this->assertLessThanOrEqual(15_000, max($ttls));
        $this->assertGreaterThan(10, count(array_unique($ttls)));
    }

    /**
     * @return list<int>
     */
    private function sampleTtls(ExpirationPolicy $policy): array
    {
        return array_map(static fn (): int => $policy->nextTtl(), range(1, 500));
    }
}
