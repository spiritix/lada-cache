<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Config;

/**
 * Manager decides whether the current query, as reflected by a `Reflector`, should be cached.
 *
 * Architectural notes:
 * - This class is immutable and declared as readonly; configuration values are captured at construction.
 * - Inclusion has precedence: when `lada-cache.include_tables` is non-empty, all referenced tables must be included.
 * - Otherwise an exclusion list `lada-cache.exclude_tables` is honored.
 */
final readonly class Manager
{
    private bool $cacheActive;

    /** @var string[] */
    private array $includeTables;

    /** @var string[] */
    private array $excludeTables;

    public function __construct(private Reflector $reflector)
    {
        $this->cacheActive = (bool) Config::get('lada-cache.active', true);
        $this->includeTables = (array) Config::get('lada-cache.include_tables', []);
        $this->excludeTables = (array) Config::get('lada-cache.exclude_tables', []);
    }

    public function shouldCache(): bool
    {
        // Avoid caching while a DB transaction is active to prevent serving
        // stale data within the same transaction (invalidations flush on commit).
        if ($this->reflector->inTransaction()) {
            return false;
        }

        return $this->cacheActive && $this->tablesCacheable();
    }

    private function tablesCacheable(): bool
    {
        $tables = $this->reflector->getTables();

        if ($tables === []) {
            return true;
        }

        // Inclusive mode → all must be explicitly listed
        if ($this->includeTables !== []) {
            foreach ($tables as $table) {
                if (! in_array($table, $this->includeTables, true)) {
                    return false;
                }
            }

            return true;
        }

        // Exclusive mode → none may appear in the blacklist
        foreach ($tables as $table) {
            if (in_array($table, $this->excludeTables, true)) {
                return false;
            }
        }

        return true;
    }
}
