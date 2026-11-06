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
 * - Keys and tags are scoped by the current `Generation` and prefixed via `Redis::prefix()`.
 * - `retireCurrentGeneration()` advances the generation, which atomically retires every
 *   entry on all hosts. `pruneRetiredGenerations()` deletes keys of older generations
 *   and `flush()` does both. Pruning safely handles
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

    public function currentGeneration(): Generation
    {
        return $this->redis->currentGeneration();
    }

    public function set(Generation $generation, string $key, array $tags, mixed $data): void
    {
        $key = $this->redis->prefix($generation->scope($key));
        $value = $this->encoder->encode($data);

        if ($this->expirationTime > 0) {
            $this->redis->set($key, $value, 'EX', $this->expirationTime);
        } else {
            $this->redis->set($key, $value);
        }

        foreach ($generation->scopeAll($tags) as $tag) {
            $this->redis->sadd($this->redis->prefix($tag), $key);
        }
    }

    public function get(Generation $generation, string $key): mixed
    {
        $encoded = $this->redis->get($this->redis->prefix($generation->scope($key)));

        return $encoded === null ? null : $this->encoder->decode($encoded);
    }

    public function repairTagMembership(Generation $generation, string $key, array $tags): void
    {
        $prefixedKey = $this->redis->prefix($generation->scope($key));

        foreach ($generation->scopeAll($tags) as $tag) {
            try {
                $this->redis->sadd($this->redis->prefix($tag), $prefixedKey);
            } catch (Throwable $e) {
                Log::warning('[LadaCache] Tag repair failed: '.$e->getMessage());
            }
        }
    }

    public function flush(): void
    {
        $this->retireCurrentGeneration();
        $this->pruneRetiredGenerations();
    }

    public function retireCurrentGeneration(): Generation
    {
        return $this->redis->advanceGeneration();
    }

    public function pruneRetiredGenerations(): void
    {
        $current = $this->redis->currentGeneration();

        try {
            $connectionPrefix = (string) (config('database.redis.options.prefix') ?? '');
            $ladaPrefix = $this->redis->prefix('');
            $generationKey = $this->redis->generationKey();

            $keys = array_map(
                static fn (string $key): string => $connectionPrefix !== '' && str_starts_with($key, $connectionPrefix)
                    ? substr($key, strlen($connectionPrefix))
                    : $key,
                $this->redis->keys($this->redis->prefix('*')) ?: []
            );

            $staleKeys = array_values(array_filter(
                $keys,
                static fn (string $key): bool => $key !== $generationKey
                    && ! $current->retains(substr($key, strlen($ladaPrefix)))
            ));

            if ($staleKeys !== []) {
                foreach (array_chunk($staleKeys, 1000) as $batch) {
                    try {
                        $this->redis->unlink(...$batch);
                    } catch (Throwable) {
                        $this->redis->del(...$batch);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('[LadaCache] Pruning retired generations failed: '.$e->getMessage());
        }
    }
}
