<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Spiritix\LadaCache\Database\QueryBuilder as LadaQueryBuilder;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

/**
 * Lada Cache database connection.
 *
 * This connection decorates Laravel's `Illuminate\\Database\\Connection` to return
 * a custom `QueryBuilder` that integrates with Lada Cache, enabling transparent
 * query caching and invalidation where supported by the package.
 *
 * Driver-specific methods (e.g., MySqlConnection::getLastInsertId) are proxied
 * to the underlying base connection via __call() to maintain full compatibility.
 */
final class Connection extends \Illuminate\Database\Connection
{
    private ?\Illuminate\Database\Connection $baseConnection = null;

    /**
     * {@inheritDoc}
     */
    public function setBaseConnection(\Illuminate\Database\Connection $connection): void
    {
        $this->baseConnection = $connection;
    }

    /**
     * {@inheritDoc}
     */
    public function query()
    {
        // When Lada Cache is disabled, use Laravel's default query builder
        if (! (bool) config('lada-cache.active', true)) {
            return parent::query();
        }

        /** @var LadaQueryHandler $handler */
        $handler = app('lada.handler');

        return new LadaQueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }

    /**
     * @inheritDoc
     */
    public function __call($method, $parameters)
    {
        if ($this->baseConnection && method_exists($this->baseConnection, $method)) {
            return $this->baseConnection->{$method}(...$parameters);
        }

        return parent::__call($method, $parameters);
    }
}
