<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Throwable;
use UnexpectedValueException;

/**
 * Persistence layer for Lada Cache backed by Redis.
 *
 * Architectural notes:
 * - Every operation is a single atomic Lua script, see `LuaScripts` for the key layout.
 * - `lookup()` captures the generation and the fingerprint of the tags' version tokens
 *   before the query runs; `store()` saves the result under that fingerprint, so any
 *   invalidation in between makes the stored entry unreachable instead of stale.
 * - `retireCurrentGeneration()` replaces the generation id, retiring every entry on all
 *   hosts at once; `pruneRetiredGenerations()` deletes leftovers in bounded SCAN batches.
 * - Methods throw on Redis failures; callers decide how to degrade.
 */
final class Cache
{
    private const int PRUNE_BATCH_SIZE = 1000;

    private const array SCHEMA_CHANGE_RETRY_DELAYS_MICROSECONDS = [100_000, 300_000, 900_000];

    private readonly ExpirationPolicy $expiration;

    public function __construct(
        private readonly Redis $redis,
        private readonly Encoder $encoder,
        ?ExpirationPolicy $expiration = null,
    ) {
        $this->expiration = $expiration ?? ExpirationPolicy::fromConfig();
    }

    /**
     * @param  array<string>  $tags
     */
    public function lookup(string $key, array $tags): CacheHit|CacheMiss
    {
        $reply = $this->redis->evaluate(LuaScripts::LOOKUP, [
            $key,
            self::freshToken(),
            $this->expiration->nextTtl(),
            ...self::canonicalTags($tags),
        ]);

        if (! is_array($reply) || ! isset($reply[0], $reply[1])) {
            throw new UnexpectedValueException('Malformed Lada Cache lookup reply for key '.$key);
        }

        if (isset($reply[2])) {
            $rows = $this->encoder->decode((string) $reply[2]);

            if ($rows !== null) {
                return new CacheHit($rows);
            }
        }

        return new CacheMiss(new Generation((string) $reply[0]), $key, (string) $reply[1]);
    }

    /**
     * @param  array<mixed>  $rows
     */
    public function store(CacheMiss $miss, array $rows): void
    {
        $this->redis->evaluate(LuaScripts::STORE, [
            $miss->generation->id,
            $miss->key,
            $miss->fingerprint,
            $this->expiration->nextTtl(),
            $this->encoder->encode($rows),
        ]);
    }

    /**
     * @param  array<string>  $tags
     */
    public function invalidate(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $this->redis->evaluate(LuaScripts::INVALIDATE, [
            self::freshToken(),
            $this->expiration->nextTtl(),
            ...self::canonicalTags($tags),
        ]);
    }

    public function flush(): void
    {
        $this->retireCurrentGeneration();
        $this->pruneRetiredGenerations();
    }

    public function retireCurrentGeneration(): void
    {
        $this->redis->evaluate(LuaScripts::RETIRE, [self::freshToken()]);
    }

    /**
     * Retires the generation even while Redis is in a cooldown, retrying with backoff, because
     * a missed retire after a schema change lets hosts serve results of the previous schema.
     */
    public function retireAfterSchemaChange(): void
    {
        foreach (self::SCHEMA_CHANGE_RETRY_DELAYS_MICROSECONDS as $delay) {
            try {
                $this->redis->evaluateIgnoringCooldown(LuaScripts::RETIRE, [self::freshToken()]);

                return;
            } catch (Throwable) {
                usleep($delay);
            }
        }

        $this->redis->evaluateIgnoringCooldown(LuaScripts::RETIRE, [self::freshToken()]);
    }

    public function pruneRetiredGenerations(): int
    {
        $cursor = '0';
        $deleted = 0;

        do {
            $reply = $this->redis->evaluate(LuaScripts::PRUNE_BATCH, [$cursor, self::PRUNE_BATCH_SIZE]);

            if (! is_array($reply) || ! isset($reply[0], $reply[1])) {
                throw new UnexpectedValueException('Malformed Lada Cache prune reply at cursor '.$cursor);
            }

            $cursor = (string) $reply[0];
            $deleted += (int) $reply[1];
        } while ($cursor !== '0');

        return $deleted;
    }

    /**
     * @param  array<string>  $tags
     * @return list<string>
     */
    private static function canonicalTags(array $tags): array
    {
        $unique = array_values(array_unique($tags));
        sort($unique, SORT_STRING);

        return $unique;
    }

    private static function freshToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
