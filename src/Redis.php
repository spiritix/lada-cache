<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

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
 */
final readonly class Redis
{
    private string $prefix;
    private Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? RedisFacade::connection();
        $this->prefix = (string) config('lada-cache.prefix', 'lada:');
    }

    public function prefix(string $key): string
    {
        return $this->prefix . $key;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->connection->{$name}(...$arguments);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
