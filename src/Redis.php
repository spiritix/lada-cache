<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Generator;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis as RedisFacade;

/**
 * Thin Redis proxy providing custom Lada Cache prefixing and raw command passthrough.
 *
 * This class centralizes access to the framework Redis connection used by Lada Cache
 * while applying a package-specific key prefix. All methods invoked on this proxy
 * are forwarded to the underlying `Illuminate\Redis\Connections\Connection` instance
 * via `__call`, keeping behavior consistent with Laravel's Redis API.
 *
 * Architectural notes:
 * - Keys should be prefixed using `prefix()` before being written to Redis to avoid
 *   collisions with application keys.
 * - The class is marked `readonly` as its state is fully defined at construction.
 *
 * The `@method` declarations below expose forwarded Redis commands to static
 * analyzers (PHPStan/Larastan). PHP runtime dispatch is unaffected.
 *
 * @method mixed set(string $key, mixed $value, mixed ...$options)
 * @method string|null get(string $key)
 * @method int sadd(string $key, mixed ...$members)
 * @method int srem(string $key, mixed ...$members)
 * @method int sismember(string $key, mixed $member)
 * @method array<int, string> smembers(string $key)
 * @method array<int, string> keys(string $pattern)
 * @method int del(string ...$keys)
 * @method int unlink(string ...$keys)
 * @method int exists(string ...$keys)
 * @method mixed multi(?callable $callback = null)
 * @method array<int, mixed> exec()
 * @method array<int, mixed> pipeline(?callable $callback = null)
 */
final readonly class Redis
{
    private string $prefix;

    private Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        if ($connection !== null) {
            $this->connection = $connection;
        } else {
            $connectionName = (string) config('lada-cache.redis_connection', 'cache');
            $this->connection = RedisFacade::connection($connectionName);
        }
        $this->prefix = (string) config('lada-cache.prefix', 'lada:');
    }

    public function prefix(string $key): string
    {
        return $this->prefix.$key;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->connection->{$name}(...$arguments);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Iterate Redis keys matching a glob pattern in cursor-driven batches.
     *
     * Wraps both PhpRedis (`scan`) and Predis (`scan`) cursor APIs in a single
     * Generator interface so callers don't need to branch on the client type.
     * Yields one batch (array of keys) per Redis round-trip. Non-blocking —
     * safe to run against production with millions of keys.
     *
     * @return Generator<int, array<int, string>, mixed, void>
     */
    public function scanKeys(string $pattern, int $count = 1000): Generator
    {
        $client = $this->connection->client();

        // PhpRedis native client
        if ($client instanceof \Redis) {
            $cursor = null;

            do {
                $keys = $client->scan($cursor, $pattern, $count);

                if ($keys !== false && $keys !== []) {
                    yield $keys;
                }
            } while ($cursor !== 0);

            return;
        }

        // Predis client (object guard satisfies PHPStan when client() typehint is mixed).
        // Predis\Client exposes `scan` via __call magic, so method_exists() returns false —
        // is_callable() honors __call and correctly probes Predis (and any compatible client).
        if (is_object($client) && is_callable([$client, 'scan'])) {
            $cursor = '0';

            do {
                $result = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => $count]);
                $cursor = (string) $result[0];
                $keys = $result[1] ?? [];

                if ($keys !== []) {
                    yield $keys;
                }
            } while ($cursor !== '0');
        }
    }

    /**
     * Iterate members of a Redis SET in cursor-driven batches via SSCAN.
     *
     * Preferred over SMEMBERS for large tag sets because:
     *   1. SMEMBERS is O(N) and blocks Redis for the duration.
     *   2. The response can balloon client memory for million-member sets.
     * Yields one batch (array of members) per Redis round-trip.
     *
     * @return Generator<int, array<int, string>, mixed, void>
     */
    public function sScanMembers(string $key, int $count = 1000): Generator
    {
        $client = $this->connection->client();

        // PhpRedis native client
        if ($client instanceof \Redis) {
            // SCAN_PREFIX prefixes every *SCAN MATCH pattern, but SSCAN's pattern
            // filters members, not keys: `*` would become `<prefix>*` and drop them all.
            // getOption() returns a bit field; setOption() takes a command that
            // flips one bit (SCAN_NOPREFIX clears PREFIX and leaves RETRY alone).
            $scanOption = $client->getOption(\Redis::OPT_SCAN);
            $prefixesPattern = ($scanOption & \Redis::SCAN_PREFIX) !== 0;
            $cursor = null;

            do {
                if ($prefixesPattern) {
                    $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NOPREFIX);
                }

                try {
                    $members = $client->sScan($key, $cursor, '*', $count);
                } finally {
                    if ($prefixesPattern) {
                        $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
                    }
                }

                if ($members !== false && $members !== []) {
                    yield $members;
                }
            } while ($cursor !== 0);

            return;
        }

        // Predis client — sscan is exposed via Predis\Client::__call, so use is_callable()
        // (not method_exists, which returns false for magic methods).
        if (is_object($client) && is_callable([$client, 'sscan'])) {
            $cursor = '0';

            do {
                $result = $client->sscan($key, $cursor, ['MATCH' => '*', 'COUNT' => $count]);
                $cursor = (string) $result[0];
                $members = $result[1] ?? [];

                if ($members !== []) {
                    yield $members;
                }
            } while ($cursor !== '0');
        }
    }
}
