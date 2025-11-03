<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

/**
 * Lada Cache-aware Postgres connection.
 */
final class PostgresConnection extends BasePostgresConnection
{
    /** {@inheritDoc} */
    public function query()
    {
        if (! (bool) config('lada-cache.active', true)) {
            return parent::query();
        }

        /** @var LadaQueryHandler $handler */
        $handler = app('lada.handler');

        return new QueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
