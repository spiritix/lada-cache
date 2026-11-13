<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Spiritix\LadaCache\Services;

/**
 * Lada Cache-aware Postgres connection.
 */
final class PostgresConnection extends BasePostgresConnection implements RunsBuilderStatements
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
