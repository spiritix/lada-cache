<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Stats;

use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\Events\LadaCacheActivity;
use Spiritix\LadaCache\Redis;
use Throwable;

/**
 * Per-table cache activity counter (hit / miss / invalidate).
 *
 * Aggregates {@see LadaCacheActivity} events in process memory and periodically
 * flushes the aggregate to Redis as HASH counters keyed by hour bucket:
 *
 *   lada:stats:YYYYMMDDHH
 *     field "users:hit"        → 42819
 *     field "users:miss"       → 512
 *     field "users:invalidate" → 120
 *     field "orders:hit"       → 2840
 *     ...
 *
 * The hash is given a configurable TTL so older buckets self-evict.
 *
 * Flush is triggered by whichever condition fires first:
 *   1. Pending distinct (table:action) keys exceed `maxBatchSize`.
 *   2. Time since last flush exceeds `maxIntervalSeconds`.
 *   3. The application terminates (via `Application::terminating()` hook
 *      registered by the service provider).
 *
 * Runtime-agnostic: works under Octane (singleton state persists across
 * requests), FPM (per-request lifecycle), queue workers, and the scheduler.
 * No dependency on Octane-specific timers.
 *
 * Failure mode: a flush exception is caught and the pending counters are
 * restored so the next flush retries the lost batch. Telemetry must never
 * break a request. A sustained outage is bounded by `$maxPendingSize` —
 * the oldest entries are dropped (with an error log) before the buffer
 * can OOM the worker.
 *
 * Note: this class is intentionally NOT marked `readonly` at the class level
 * because the `$pending` buffer is mutated on every event. Constructor
 * parameters remain individually `readonly`.
 */
final class StatsCounter
{
    /** @var array<string, int> table:action => count */
    private array $pending = [];

    private float $lastFlush;

    public function __construct(
        private readonly Redis $redis,
        private readonly int $maxBatchSize = 100,
        private readonly float $maxIntervalSeconds = 5.0,
        private readonly int $bucketTtlSeconds = 86400 * 7,
        // Hard cap on `$pending` size. If Redis is unreachable for long enough
        // that the restore-on-failure path keeps growing the buffer, oldest
        // entries are dropped and an error is logged. Without this guard a
        // stuck flush path could OOM the worker under heavy hit/miss traffic.
        private readonly int $maxPendingSize = 10000,
    ) {
        $this->lastFlush = microtime(true);
    }

    public function handle(LadaCacheActivity $event): void
    {
        $table = $event->table ?? 'unknown';
        $field = $table.':'.$event->action;

        $this->pending[$field] = ($this->pending[$field] ?? 0) + 1;

        if (count($this->pending) >= $this->maxBatchSize
            || (microtime(true) - $this->lastFlush) >= $this->maxIntervalSeconds) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            $this->lastFlush = microtime(true);

            return;
        }

        $batch = $this->pending;
        $this->pending = [];
        $this->lastFlush = microtime(true);

        try {
            $bucket = $this->bucketKey();
            // TTL is captured at construction time and re-passed into the
            // pipeline closure to avoid re-reading config in the hot path.
            $ttl = $this->bucketTtlSeconds;

            $this->redis->pipeline(static function ($pipe) use ($bucket, $batch, $ttl): void {
                foreach ($batch as $field => $count) {
                    $pipe->hincrby($bucket, $field, $count);
                }
                $pipe->expire($bucket, $ttl);
            });
        } catch (Throwable $e) {
            Log::warning('[LadaCache] Stats flush failed: '.$e->getMessage());

            // Restore so the next flush retries the lost batch.
            foreach ($batch as $field => $count) {
                $this->pending[$field] = ($this->pending[$field] ?? 0) + $count;
            }

            $this->enforceMaxPendingSize();
        }
    }

    /**
     * Drop the oldest entries from `$pending` if the buffer is over the
     * configured cap after a failed flush restored its batch. Without this
     * a sustained Redis outage would OOM the worker on a high-traffic site.
     */
    private function enforceMaxPendingSize(): void
    {
        if ($this->maxPendingSize <= 0) {
            return;
        }

        $excess = count($this->pending) - $this->maxPendingSize;

        if ($excess <= 0) {
            return;
        }

        // PHP arrays preserve insertion order, so slicing from the start drops
        // the oldest (table:action) keys first.
        $this->pending = array_slice($this->pending, $excess, null, true);

        Log::error(sprintf(
            '[LadaCache] StatsCounter overflow — dropped %d entries (cap=%d). Redis flush is failing; investigate.',
            $excess,
            $this->maxPendingSize,
        ));
    }

    /**
     * Return pending counters without flushing — primarily for tests/diagnostics.
     *
     * @return array<string, int>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    private function bucketKey(): string
    {
        // Apply Lada's configured prefix so the bucket sits alongside other
        // Lada-owned Redis keys (and tests asserting against the expected key
        // via Redis::prefix() can find it).
        //
        // UTC bucket key (`gmdate`) — server timezone drift would otherwise
        // produce different bucket names on writer and reader if they happen
        // to run with different `date.timezone` settings (e.g. two workers
        // started in different containers, or scheduler running on a host
        // with a non-UTC clock). StatsReader uses the same `gmdate('YmdH')`
        // format for symmetry.
        return $this->redis->prefix('lada:stats:'.gmdate('YmdH'));
    }
}
