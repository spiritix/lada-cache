<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Stats;

use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Stats\StatsReader;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Verifies {@see StatsReader} aggregation behavior:
 *   - Aggregates field counts from a single bucket into per-table totals.
 *   - Sums across multiple hourly buckets (lookback window).
 *   - Ignores malformed fields and unknown action labels.
 *   - Returns empty when lookback is non-positive (no Redis round-trip).
 */
class StatsReaderTest extends TestCase
{
    private Redis $redis;

    private StatsReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new Redis;
        $this->reader = new StatsReader($this->redis);
    }

    public function test_read_returns_empty_when_lookback_is_non_positive(): void
    {
        // Seed something so we can verify it isn't read.
        $bucket = $this->redis->prefix('lada:stats:'.gmdate('YmdH'));
        $this->redis->hset($bucket, 'users:hit', 5);

        $this->assertSame([], $this->reader->readAllActivity(0));
        $this->assertSame([], $this->reader->readAllActivity(-1));
    }

    public function test_read_returns_empty_when_no_buckets_exist(): void
    {
        $this->assertSame([], $this->reader->readAllActivity(24));
    }

    public function test_read_aggregates_single_bucket_per_table(): void
    {
        $bucket = $this->redis->prefix('lada:stats:'.gmdate('YmdH'));
        $this->redis->hset($bucket, 'users:hit', 100);
        $this->redis->hset($bucket, 'users:miss', 20);
        $this->redis->hset($bucket, 'users:invalidate', 5);
        $this->redis->hset($bucket, 'orders:hit', 50);

        $result = $this->reader->readAllActivity(1);

        $this->assertSame([
            'users' => ['hit' => 100, 'miss' => 20, 'invalidate' => 5],
            'orders' => ['hit' => 50, 'miss' => 0, 'invalidate' => 0],
        ], $result);
    }

    public function test_read_sums_across_multiple_hourly_buckets(): void
    {
        $now = time();
        $thisHour = $this->redis->prefix('lada:stats:'.gmdate('YmdH', $now));
        $lastHour = $this->redis->prefix('lada:stats:'.gmdate('YmdH', $now - 3600));
        $threeHoursAgo = $this->redis->prefix('lada:stats:'.gmdate('YmdH', $now - 3 * 3600));

        $this->redis->hset($thisHour, 'users:hit', 10);
        $this->redis->hset($lastHour, 'users:hit', 20);
        $this->redis->hset($threeHoursAgo, 'users:hit', 30);

        // Lookback 2 hours → only thisHour + lastHour (3-hours-ago excluded).
        $result = $this->reader->readAllActivity(2);
        $this->assertSame(30, $result['users']['hit']);

        // Lookback 4 hours → covers all three buckets.
        $result4h = $this->reader->readAllActivity(4);
        $this->assertSame(60, $result4h['users']['hit']);
    }

    public function test_read_ignores_malformed_field_names(): void
    {
        $bucket = $this->redis->prefix('lada:stats:'.gmdate('YmdH'));
        $this->redis->hset($bucket, 'users:hit', 10);
        $this->redis->hset($bucket, 'no_colon_at_all', 99);      // missing :action
        $this->redis->hset($bucket, 'users:unknown_action', 77); // unknown action label

        $result = $this->reader->readAllActivity(1);

        $this->assertSame([
            'users' => ['hit' => 10, 'miss' => 0, 'invalidate' => 0],
        ], $result, 'Malformed / unknown-action fields must be silently dropped.');
    }

    public function test_read_treats_missing_old_buckets_as_zero_contribution(): void
    {
        // Simulates `activity_lookback_hours` > `activity_bucket_ttl_seconds/3600`: only
        // the recent bucket is populated, older keys do not exist (would have
        // expired). HGETALL on a missing key returns empty → 0 contribution.
        $now = time();
        $thisHour = $this->redis->prefix('lada:stats:'.gmdate('YmdH', $now));
        $this->redis->hset($thisHour, 'users:hit', 7);

        // Look back 200 hours; only thisHour has data, the rest are missing.
        $result = $this->reader->readAllActivity(200);

        $this->assertSame(
            ['users' => ['hit' => 7, 'miss' => 0, 'invalidate' => 0]],
            $result,
            'missing (expired) buckets must contribute 0 without failing the read',
        );
    }

    public function test_read_handles_table_names_with_colon_safely(): void
    {
        // explode(..., 2) limits to 2 parts so a table name containing colons
        // ends up labeled with the LAST segment as action — which would then
        // be filtered out as "unknown action" unless it happens to match
        // hit/miss/invalidate. Realistic table names don't contain colons, but
        // this guards us from quietly producing junk if some downstream emits
        // a weird field.
        $bucket = $this->redis->prefix('lada:stats:'.gmdate('YmdH'));
        $this->redis->hset($bucket, 'weird:table:hit', 5);

        $result = $this->reader->readAllActivity(1);

        // First split: table='weird', action='table:hit' → unknown action, dropped.
        $this->assertSame([], $result);
    }
}
