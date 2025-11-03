<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\SqliteConnection as BaseSqliteConnection;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

/**
 * Lada Cache-aware SQLite connection.
 */
final class SqliteConnection extends BaseSqliteConnection
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
