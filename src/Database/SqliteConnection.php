<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\SQLiteConnection as BaseSqliteConnection;
use Spiritix\LadaCache\Services;

/**
 * Lada Cache-aware SQLite connection.
 */
final class SqliteConnection extends BaseSqliteConnection implements RunsBuilderStatements
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
