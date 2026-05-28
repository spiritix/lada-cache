<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persistence layer for Lada Cache backed by Redis.
 *
 * This class stores and retrieves encoded query results under a package-specific
 * key prefix and maintains tag membership used for invalidation.
 *
 * Architectural notes:
 * - Keys are prefixed via `Redis::prefix()` to avoid collisions.
 * - `flush()` removes all keys for the Lada prefix and safely handles
 *   connection-level Redis prefixes (Predis/PhpRedis) by stripping the
 *   connection prefix before deletion and batching deletes (preferring UNLINK).
 * - Positive TTLs receive ±N% random jitter (default ±15%) before SET EX so
 *   that keys cached within the same second do not expire in lockstep,
 *   avoiding a synchronized DB miss wave (thundering-herd guard).
 */
final class Cache
{
    private readonly int $expirationTime;
    private readonly int $jitterPct;

    public function __construct(
        private readonly Redis $redis,
        private readonly Encoder $encoder,
        ?int $expirationTime = null,
        ?int $jitterPct = null,
    ) {
        $this->expirationTime = $expirationTime ?? (int) config('lada-cache.expiration_time', 0);
        $rawJitter = $jitterPct ?? (int) config('lada-cache.ttl_jitter_pct', 15);
        // Clamp to [0, 100]; negative or excessive values would produce invalid TTLs.
        $this->jitterPct = max(0, min(100, $rawJitter));
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->redis->prefix($key));
    }

    public function set(string $key, array $tags, mixed $data): void
    {
        $key = $this->redis->prefix($key);
        $value = $this->encoder->encode($data);
        $effectiveTtl = $this->applyJitter($this->expirationTime);

        if ($effectiveTtl > 0) {
            $this->redis->set($key, $value, 'EX', $effectiveTtl);
        } else {
            $this->redis->set($key, $value);
        }

        foreach ($tags as $tag) {
            $this->redis->sadd($this->redis->prefix($tag), $key);
        }
    }

    public function get(string $key): mixed
    {
        $encoded = $this->redis->get($this->redis->prefix($key));

        return $encoded === null ? null : $this->encoder->decode($encoded);
    }

    public function repairTagMembership(string $key, array $tags): void
    {
        $prefixedKey = $this->redis->prefix($key);

        foreach ($tags as $tag) {
            try {
                $this->redis->sadd($this->redis->prefix($tag), $prefixedKey);
            } catch (Throwable $e) {
                Log::warning('[LadaCache] Tag repair failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Apply ±jitterPct random jitter to a positive TTL.
     *
     * Edge cases:
     *   - TTL <= 0          : returned unchanged (0/null mean "persist forever").
     *   - jitterPct = 0     : returned unchanged (deterministic / disabled).
     *   - delta rounds to 0 : returned unchanged (TTL too small to be perturbed).
     *   - Result is clamped to at least 1 second so jitter never produces a
     *     non-positive TTL that would silently downgrade SET EX into
     *     "persist forever".
     */
    private function applyJitter(int $ttl): int
    {
        if ($ttl <= 0 || $this->jitterPct === 0) {
            return $ttl;
        }

        $delta = (int) round($ttl * ($this->jitterPct / 100));

        if ($delta <= 0) {
            return $ttl;
        }

        return max(1, $ttl + random_int(-$delta, $delta));
    }

    public function flush(): void
    {
        try {
            $connectionPrefix = (string) (config('database.redis.options.prefix') ?? '');

            // Fetch all Lada keys as returned by the connection (includes connection prefix if set)
            $keys = $this->redis->keys($this->redis->prefix('*'));

            if (! empty($keys)) {
                // Strip the connection-level prefix so the driver applies it exactly once when deleting
                $toDelete = $connectionPrefix !== ''
                    ? array_map(
                        static fn(string $k): string => str_starts_with($k, $connectionPrefix) ? substr($k, strlen($connectionPrefix)) : $k,
                        $keys
                    )
                    : $keys;

                foreach (array_chunk($toDelete, 1000) as $batch) {
                    try {
                        $this->redis->unlink(...$batch);
                    } catch (Throwable) {
                        $this->redis->del(...$batch);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('[LadaCache] Redis flush failed: '.$e->getMessage());
        }
    }
}
