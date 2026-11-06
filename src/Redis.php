<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Predis\Response\ServerException;
use Redis as PhpRedisClient;
use RuntimeException;
use Spiritix\LadaCache\Support\RedisAvailability;
use Throwable;

/**
 * Lazy Redis access for Lada Cache with a circuit breaker.
 *
 * Architectural notes:
 * - The connection is resolved on first use, so building query builders never touches Redis.
 * - After any failed script, including a failed connection, further scripts are refused for a
 *   cooldown period (see `RedisAvailability`), so an unreachable Redis costs at most one
 *   timeout per cooldown instead of one per query.
 * - `__call` forwards raw commands to the underlying connection.
 */
final class Redis
{
    private readonly string $prefix;

    private readonly string $connectionName;

    private readonly RedisAvailability $availability;

    private ?string $keyBase = null;

    public function __construct(
        private ?Connection $connection = null,
        ?RedisAvailability $availability = null,
    ) {
        $this->prefix = (string) config('lada-cache.prefix', 'lada:');
        $this->connectionName = $connection?->getName() ?? (string) config('lada-cache.redis_connection', 'cache');
        $this->availability = $availability ?? RedisAvailability::detect($this->processStateKey('unavailable'));
    }

    public function prefix(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * Key for state kept outside Redis about this Redis connection and prefix, such as in APCu.
     */
    public function processStateKey(string $purpose): string
    {
        return 'lada-cache:'.$purpose.':'.$this->connectionName.':'.$this->prefix;
    }

    private function keyBase(): string
    {
        return $this->keyBase ??= $this->connectionPrefix().$this->prefix;
    }

    /**
     * Runs a script from `LuaScripts`, passing the key base as ARGV[1] ahead of the arguments.
     * Scripts run by SHA so their source crosses the network only once per Redis server.
     *
     * @param  list<string|int>  $arguments
     */
    public function evaluate(string $script, array $arguments): mixed
    {
        if ($this->availability->isCoolingDown()) {
            throw new CacheUnavailableException('Redis is skipped for a cooldown period after a previous failure');
        }

        return $this->evaluateNow($script, $arguments);
    }

    /**
     * Like `evaluate()`, but tries Redis even during a cooldown. For rare operations whose
     * failure compromises freshness, such as retiring the generation after a schema change.
     *
     * @param  list<string|int>  $arguments
     */
    public function evaluateIgnoringCooldown(string $script, array $arguments): mixed
    {
        return $this->evaluateNow($script, $arguments);
    }

    /**
     * @param  list<string|int>  $arguments
     */
    private function evaluateNow(string $script, array $arguments): mixed
    {
        try {
            $result = $this->runBySha($script, [$this->keyBase(), ...$arguments]);
        } catch (Throwable $exception) {
            $this->availability->markUnavailable();

            throw $exception;
        }

        if ($result === false) {
            $this->availability->markUnavailable();

            throw new RuntimeException('Redis rejected a Lada Cache script: '.$this->lastClientError());
        }

        $this->availability->markAvailable();

        return $result;
    }

    /**
     * @param  list<string|int>  $arguments
     */
    private function runBySha(string $script, array $arguments): mixed
    {
        $connection = $this->getConnection();
        $sha = sha1($script);

        if ($connection instanceof PhpRedisConnection) {
            $client = $connection->client();
            $client->clearLastError();
            $result = $connection->command('evalsha', [$sha, $arguments, 0]);

            if ($result === false && str_starts_with((string) $client->getLastError(), 'NOSCRIPT')) {
                $client->clearLastError();

                return $connection->command('eval', [$script, $arguments, 0]);
            }

            return $result;
        }

        try {
            return $connection->command('evalsha', [$sha, 0, ...$arguments]);
        } catch (ServerException $exception) {
            if ($exception->getErrorType() !== 'NOSCRIPT') {
                throw $exception;
            }

            return $connection->command('eval', [$script, 0, ...$arguments]);
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->getConnection()->{$name}(...$arguments);
    }

    public function getConnection(): Connection
    {
        return $this->connection ??= RedisFacade::connection($this->connectionName);
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
}
