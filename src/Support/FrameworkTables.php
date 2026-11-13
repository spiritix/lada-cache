<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Tables Laravel itself writes on nearly every request or job when sessions, cache or queues
 * use the database. Caching their reads costs a lookup and a store per request for entries
 * the next write throws away, so they are left out of caching.
 */
final class FrameworkTables
{
    /**
     * @return list<string>
     */
    public static function volatile(): array
    {
        $tables = [];

        if (config('session.driver') === 'database') {
            $tables[] = config('session.table') ?? 'sessions';
        }

        foreach ((array) config('cache.stores', []) as $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'database') {
                $tables[] = $store['table'] ?? 'cache';
                $tables[] = $store['lock_table'] ?? 'cache_locks';
            }
        }

        foreach ((array) config('queue.connections', []) as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
                $tables[] = $connection['table'] ?? 'jobs';
            }
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $tables[] = config('queue.failed.table') ?? 'failed_jobs';
        }

        if ((config('queue.batching.driver') ?? 'database') === 'database') {
            $tables[] = config('queue.batching.table');
        }

        return array_values(array_unique(array_filter($tables, 'is_string')));
    }
}
