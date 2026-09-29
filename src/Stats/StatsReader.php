<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Stats;

use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;

/**
 * Reads StatsCounter-produced `lada:stats:*` hourly HASH buckets and aggregates
 * activity counts per (table, action) across a recent time window.
 *
 * Used by {@see \Spiritix\LadaCache\Console\CalibrateCommand} to enrich the
 * OBJECT IDLETIME signal with read / write frequency before computing a
 * per-model TTL.
 *
 * Returned shape per table:
 *   [
 *     'hit'        => int,  // cache HIT count
 *     'miss'       => int,  // cache MISS count (DB query executed)
 *     'invalidate' => int,  // tag/key invalidation count (writes to that table)
 *   ]
 *
 * Bucket key format: `lada:stats:YYYYMMDDHH` (the same wall-clock format
 * StatsCounter uses on write). Missing buckets contribute 0 — pipelined
 * HGETALL returns an empty array for non-existent keys, which is benign.
 *
 * Failure semantics: Redis/pipeline exceptions are NOT swallowed here. The
 * caller ({@see CalibrateCommand::loadActivity()}) is responsible for catching
 * them so it can distinguish a real Redis failure ("idletime_only" fallback)
 * from a genuinely empty bucket window ("no_activity"). Swallowing at this
 * layer would collapse both into a misleading no_activity classification and
 * hide operational issues from the cron summary log + monitoring.
 *
 * Octane-safe: no mutable instance state beyond the constructor dependency.
 */
final readonly class StatsReader
{
    /**
     * Action labels emitted by {@see QueryHandler::dispatchActivity()}.
     * Kept in sync with that call site so unrecognized fields are ignored
     * rather than silently summed into a bucket they don't belong to.
     *
     * Note: no `array` type on the constant — typed class constants require
     * PHP 8.3+ and we want to keep the package compatible with older runtimes
     * that still satisfy `^8.1` in downstream apps consuming this package.
     */
    private const DEFAULT_ACTIONS = ['hit', 'miss', 'invalidate'];

    public function __construct(
        private Redis $redis,
    ) {}

    /**
     * Aggregate activity over the last $hoursBack hours, keyed by table.
     *
     * Reads all bucket keys in a single Redis pipeline round-trip. Redis or
     * pipeline failures are intentionally **not** caught here; see the class
     * PHPDoc for the rationale. A non-array pipeline reply (driver oddity) is
     * tolerated and returns empty.
     *
     * @return array<string, array{hit:int, miss:int, invalidate:int}>
     */
    public function readAllActivity(int $hoursBack): array
    {
        if ($hoursBack <= 0) {
            return [];
        }

        $buckets = $this->bucketKeysForLookback($hoursBack);

        $results = $this->redis->pipeline(static function ($pipe) use ($buckets): void {
            foreach ($buckets as $bucket) {
                $pipe->hgetall($bucket);
            }
        });

        if (! is_array($results)) {
            return [];
        }

        $aggregate = [];

        foreach ($results as $hash) {
            if (! is_array($hash) || $hash === []) {
                continue;
            }

            foreach ($hash as $field => $count) {
                $parts = explode(':', (string) $field, 2);

                if (count($parts) !== 2) {
                    continue;
                }

                [$table, $action] = $parts;

                if (! in_array($action, self::DEFAULT_ACTIONS, true)) {
                    continue;
                }

                if (! isset($aggregate[$table])) {
                    $aggregate[$table] = ['hit' => 0, 'miss' => 0, 'invalidate' => 0];
                }

                $aggregate[$table][$action] += (int) $count;
            }
        }

        return $aggregate;
    }

    /**
     * Prefixed bucket keys covering the lookback window. Inclusive of the
     * current hour bucket (where in-flight events still accumulate).
     *
     * UTC bucket key (`gmdate`) — must match the writer's format in
     * {@see StatsCounter::bucketKey()} so we don't
     * miss buckets when writer / reader run in different server timezones.
     *
     * @return string[]
     */
    private function bucketKeysForLookback(int $hoursBack): array
    {
        $now = time();
        $keys = [];

        for ($i = 0; $i < $hoursBack; $i++) {
            $hour = gmdate('YmdH', $now - ($i * 3600));
            $keys[] = $this->redis->prefix('lada:stats:'.$hour);
        }

        return $keys;
    }
}
