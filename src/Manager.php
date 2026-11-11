<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\Config;
use Spiritix\LadaCache\Support\BypassReason;
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
        return $this->bypassReason() === null;
    }

    /**
     * Why the read must not go through the cache, null when it may.
     */
    public function bypassReason(): ?BypassReason
    {
        return match (true) {
            $this->reflector->inTransaction() => BypassReason::Transaction,
            $this->reflector->isPretending() => BypassReason::Pretending,
            $this->scope === CacheScope::Models && ! $this->reflector->isModelQuery() => BypassReason::Scope,
            ! $this->cacheActive => BypassReason::Inactive,
            ! $this->tablesCacheable() => BypassReason::ExcludedTable,
            NonDeterministicSql::isNonDeterministic($this->reflector->getSql(), $this->reflector->getDialect()) => BypassReason::NonDeterministic,
            default => null,
        };
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
