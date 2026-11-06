<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Redis as PhpRedisClient;
use RuntimeException;
use Throwable;

/**
 * Lazy Redis access for Lada Cache with a per-process circuit breaker.
 *
 * Architectural notes:
 * - The connection is resolved on first use, so building query builders never touches Redis.
 * - After any failed script the proxy refuses further scripts for a cooldown period, so an
 *   unreachable Redis costs at most one timeout per cooldown instead of one per query.
 * - `__call` forwards raw commands to the underlying connection.
 */
final class Redis
{
    private const int UNAVAILABLE_COOLDOWN_NANOSECONDS = 30_000_000_000;

    private readonly string $prefix;

    private ?int $unavailableUntil = null;

    public function __construct(
        private ?Connection $connection = null,
    ) {
        $this->prefix = (string) config('lada-cache.prefix', 'lada:');
    }

    public function prefix(string $key): string
    {
        return $this->prefix.$key;
    }

    private function keyBase(): string
    {
        return $this->connectionPrefix().$this->prefix;
    }

    /**
     * Runs a script from `LuaScripts`, passing the key base as ARGV[1] ahead of the arguments.
     *
     * @param  list<string|int>  $arguments
     */
    public function evaluate(string $script, array $arguments): mixed
    {
        if ($this->unavailableUntil !== null && hrtime(true) < $this->unavailableUntil) {
            throw new CacheUnavailableException('Redis is skipped for a cooldown period after a previous failure');
        }

        try {
            $result = $this->getConnection()->eval($script, 0, $this->keyBase(), ...$arguments);
        } catch (Throwable $exception) {
            $this->markUnavailable();

            throw $exception;
        }

        if ($result === false) {
            $this->markUnavailable();

            throw new RuntimeException('Redis rejected a Lada Cache script: '.$this->lastClientError());
        }

        $this->unavailableUntil = null;

        return $result;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->getConnection()->{$name}(...$arguments);
    }

    public function getConnection(): Connection
    {
        return $this->connection ??= RedisFacade::connection((string) config('lada-cache.redis_connection', 'cache'));
    }

    private function connectionPrefix(): string
    {
        $connection = $this->getConnection();

        return match (true) {
            $connection instanceof PhpRedisConnection => (string) $connection->client()->getOption(PhpRedisClient::OPT_PREFIX),
            $connection instanceof PredisConnection => (string) ($connection->client()->getOptions()->prefix?->getPrefix() ?? ''),
            default => throw new RuntimeException('Unsupported Redis connection for Lada Cache: '.$connection::class),
        };
    }

    private function lastClientError(): string
    {
        $client = $this->connection?->client();

        return $client instanceof PhpRedisClient ? (string) $client->getLastError() : 'no error reported';
    }

    private function markUnavailable(): void
    {
        $this->unavailableUntil = hrtime(true) + self::UNAVAILABLE_COOLDOWN_NANOSECONDS;
    }
}
