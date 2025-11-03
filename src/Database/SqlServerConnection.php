<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\SqlServerConnection as BaseSqlServerConnection;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

/**
 * Lada Cache-aware SQL Server connection.
 */
final class SqlServerConnection extends BaseSqlServerConnection
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
