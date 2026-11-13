<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\SqlServerConnection as BaseSqlServerConnection;
use Spiritix\LadaCache\Services;

/**
 * Lada Cache-aware SQL Server connection.
 */
final class SqlServerConnection extends BaseSqlServerConnection implements RunsBuilderStatements
{
    use InvalidatesRawStatements;

    /** {@inheritDoc} */
    public function query()
    {
        if (! (bool) config('lada-cache.active', true)) {
            return parent::query();
        }

        $handler = Services::handler();

        return new QueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
