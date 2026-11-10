<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Config;
use Spiritix\LadaCache\Support\ConfigValue;
use Spiritix\LadaCache\Support\NonDeterministicSql;

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

    private CacheScope $scope;

    /** @var list<string> */
    private array $includeTables;

    /** @var list<string> */
    private array $excludeTables;

    public function __construct(private Reflector $reflector)
    {
        $this->cacheActive = (bool) Config::get('lada-cache.active', true);
        $this->scope = CacheScope::fromConfig();
        $this->includeTables = ConfigValue::strings('lada-cache.include_tables');
        $this->excludeTables = ConfigValue::strings('lada-cache.exclude_tables');
    }

    public function shouldCache(): bool
    {
        if ($this->reflector->inTransaction() || $this->reflector->isPretending()) {
            return false;
        }

        if ($this->scope === CacheScope::Models && ! $this->reflector->isModelQuery()) {
            return false;
        }

        if (! $this->cacheActive || ! $this->tablesCacheable()) {
            return false;
        }

        return ! NonDeterministicSql::isNonDeterministic($this->reflector->getSql(), $this->reflector->getDialect());
    }

    /**
     * Writes invalidate even for tables excluded from caching, since foreign key actions can
     * carry them into cached tables; invalidating tags nothing reads costs nothing.
     */
    public function shouldInvalidate(): bool
    {
        return $this->cacheActive && ! $this->reflector->isPretending();
    }

    private function tablesCacheable(): bool
    {
        $tables = $this->reflector->getTables();

        if ($tables === []) {
            return true;
        }

        if ($this->includeTables !== []) {
            return array_diff($tables, $this->includeTables) === [];
        }

        return array_intersect($tables, $this->excludeTables) === [];
    }
}
