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
 */
final class Cache
{
    private readonly int $expirationTime;

    public function __construct(
        private readonly Redis $redis,
        private readonly Encoder $encoder,
        ?int $expirationTime = null,
    ) {
        $this->expirationTime = $expirationTime ?? (int) config('lada-cache.expiration_time', 0);
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->redis->prefix($key));
    }

    public function set(string $key, array $tags, mixed $data): void
    {
        $key = $this->redis->prefix($key);
        $value = $this->encoder->encode($data);

        if ($this->expirationTime > 0) {
            $this->redis->set($key, $value, 'EX', $this->expirationTime);
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
