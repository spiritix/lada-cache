<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles cache storage and retrieval for database query results.
 *
 * Provides a Redis-backed layer that:
 * - Prefixes keys to avoid collisions across applications.
 * - Supports tagging for granular invalidation.
 * - Uses SCAN-based iteration for non-blocking flush operations.
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

    /**
     * Store the encoded value under the prefixed key and attach tag memberships.
     *
     * @param array<string> $tags
     */
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

    /**
     * Flush all cache keys matching the configured prefix.
     *
     * Performs an incremental, non-blocking traversal using SCAN. Any failures
     * are logged via the application logger and do not bubble exceptions.
     */
    public function flush(): void
    {
        try {
            $cursor = '0';
            $pattern = $this->redis->prefix('*');

            do {
                [$cursor, $keys] = $this->redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 1000);
                if (!empty($keys)) {
                    $this->redis->del(...$keys);
                }
            } while ($cursor !== '0' && $cursor !== 0);
        } catch (Throwable $e) {
            Log::warning('[LadaCache] Redis flush failed: ' . $e->getMessage());
        }
    }
}

