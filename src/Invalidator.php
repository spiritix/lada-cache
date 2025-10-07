<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Log;
use RedisException;
use Throwable;

/**
 * Invalidates cached entries by their associated tags.
 *
 * This service collects cache keys associated with provided tags and removes
 * both the tag sets and the referenced cache keys from Redis. It relies on
 * the package's `Redis` proxy to respect the configured Lada Cache key prefix
 * and to pass through raw Redis commands.
 */
final readonly class Invalidator
{
    public function __construct(
        private Redis $redis,
    ) {}

    /**
     * @param array<string> $tags
     * @return array<string>
     */
    public function invalidate(array $tags): array
    {
        $hashes = $this->collectAndDeleteTagSets($tags);
        $this->deleteKeys($hashes);

        return $hashes;
    }

    /**
     * @param array<string> $tags
     * @return array<string>
     */
    private function collectAndDeleteTagSets(array $tags): array
    {
        $hashes = [];

        foreach ($tags as $tag) {
            $tagKey = $this->redis->prefix($tag);

            if (!$this->redis->exists($tagKey)) {
                continue;
            }

            try {
                $this->redis->multi();
                $this->redis->smembers($tagKey);
                $this->redis->del($tagKey);
                $result = $this->redis->exec();

                if (!empty($result[0])) {
                    $hashes = [...$hashes, ...$result[0]];
                }
            } catch (Throwable $e) {
                Log::warning("[LadaCache] Failed to invalidate tag '{$tag}': {$e->getMessage()}");
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @param array<string> $keys
     */
    private function deleteKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        try {
            // Prefer UNLINK for non-blocking deletion where supported.
            try {
                $this->redis->unlink(...$keys);
            } catch (Throwable) {
                $this->redis->del(...$keys);
            }
        } catch (Throwable) {
            foreach ($keys as $key) {
                try {
                    $this->redis->del($key);
                } catch (RedisException) {
                    // ignore secondary failures
                }
            }
        }
    }
}
