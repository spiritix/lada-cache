<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\App;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\FailureReporter;
use Spiritix\LadaCache\Support\PendingInvalidations;
use Spiritix\LadaCache\Support\RawStatementEffect;
use Throwable;

/**
 * Coordinates query caching and invalidation for Eloquent/DB queries.
 *
 * Responsibilities:
 * - Decide whether a query should be cached using `Manager`.
 * - Compute cache keys with `Hasher` and tags with `Tagger`.
 * - Look up, store and invalidate results via `Cache`.
 * - Defer invalidations of writes inside a transaction until the outermost commit.
 * - Integrate with the debug collector when available.
 *
 * Reads inside a transaction bypass the cache (see `Manager`), because a transaction may see
 * its own uncommitted writes or an older snapshot.
 *
 * Every cache failure is reported and degrades to plain database access: a query runs
 * exactly once and its own exceptions propagate unchanged.
 */
final class QueryHandler
{
    private ?QueryBuilder $builder = null;

    private ?CacheCollector $collector = null;

    private readonly PendingInvalidations $pending;

    public function __construct(
        private readonly Cache $cache,
    ) {
        $this->pending = new PendingInvalidations;
    }

    public function setBuilder(QueryBuilder $builder): self
    {
        $this->builder = $builder;

        return $this;
    }

    /**
     * @param  string  $statementType  One of Reflector::QUERY_TYPE_*
     * @param  array<string, mixed>  $values  Values used by the grammar to compile the SQL (e.g., update sets)
     */
    public function invalidateQuery(string $statementType, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $this->startCollector();

        if ($this->builder === null) {
            return;
        }

        $connection = $this->builder->getConnection();

        try {
            $reflector = new Reflector($this->builder, $statementType, $values);

            if (! (new Manager($reflector))->shouldInvalidate()) {
                return;
            }

            $tags = (new Tagger($reflector, $assignedRows))->getTags();
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Invalidate, $exception, ['statement' => $statementType]);
            $this->retireGenerationNow(fn () => $this->cache->retireCurrentGeneration());

            return;
        }

        $deferred = $this->invalidateTags($connection, $tags);
        $this->stopCollector($reflector, $tags, [], $deferred ? "Invalidation queued ({$statementType})" : "Invalidation ({$statementType})");
    }

    public function invalidateRawStatement(Connection $connection, RawStatementEffect $effect): void
    {
        if ($effect->changesEverything) {
            $this->schemaChanged($connection);

            return;
        }

        foreach ($effect->tables as $table) {
            $builder = $connection->query()->from($table);

            if (! $builder instanceof QueryBuilder) {
                return;
            }

            $this->setBuilder($builder)->invalidateQuery(Reflector::QUERY_TYPE_TRUNCATE);
        }
    }

    /**
     * @param  Closure(): array<mixed>  $queryClosure
     * @return array<mixed>
     */
    public function cacheQuery(Closure $queryClosure): array
    {
        $this->startCollector();

        if ($this->builder === null) {
            return $queryClosure();
        }

        $connection = $this->builder->getConnection();

        if (! $connection instanceof Connection) {
            return $queryClosure();
        }

        try {
            $reflector = new Reflector($this->builder);
            $cacheable = (new Manager($reflector))->shouldCache();
            $key = $cacheable ? (new Hasher($reflector))->getHash() : '';
            $tags = $cacheable ? (new Tagger($reflector))->getTags() : [];
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Analyze, $exception);

            return $queryClosure();
        }

        if (! $cacheable) {
            return $queryClosure();
        }

        $lookup = $this->lookup($key, $tags, SchemaVersion::ofConnection($connection));

        if ($lookup instanceof CacheHit) {
            $this->stopCollector($reflector, $tags, $key, 'Hit');

            return $lookup->rows;
        }

        $rows = $queryClosure();

        if ($lookup instanceof CacheMiss) {
            $this->store($lookup, $rows);
        }

        $this->stopCollector($reflector, $tags, $key, $lookup instanceof CacheMiss ? 'Miss' : 'Bypass');

        return $rows;
    }

    public function transactionCommitted(ConnectionInterface $connection): void
    {
        if (self::inTransaction($connection)) {
            return;
        }

        ['tags' => $tags, 'retire' => $retire] = $this->pending->take($connection);

        if ($retire) {
            $this->retireGenerationNow(fn () => $this->cache->retireAfterSchemaChange());
        }

        if ($tags !== []) {
            $this->invalidateTagsNow($tags);
        }
    }

    public function transactionRolledBack(ConnectionInterface $connection): void
    {
        if (self::inTransaction($connection)) {
            return;
        }

        if ($this->pending->take($connection)['retire']) {
            $this->retireGenerationNow(fn () => $this->cache->retireAfterSchemaChange());
        }
    }

    /**
     * @param  array<string>  $tags
     */
    private function lookup(string $key, array $tags, SchemaVersion $schema): CacheHit|CacheMiss|null
    {
        try {
            return $this->cache->lookup($key, $tags, $schema);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Read, $exception, ['key' => $key]);

            return null;
        }
    }

    /**
     * @param  array<mixed>  $rows
     */
    private function store(CacheMiss $miss, array $rows): void
    {
        try {
            $this->cache->store($miss, $rows);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Store, $exception, ['key' => $miss->key]);
        }
    }

    /**
     * Invalidates now, or at the outermost commit when the connection is inside a transaction.
     *
     * @param  array<string>  $tags
     * @return bool Whether the invalidation was deferred
     */
    private function invalidateTags(ConnectionInterface $connection, array $tags): bool
    {
        if (! self::inTransaction($connection)) {
            $this->invalidateTagsNow($tags);

            return false;
        }

        $this->pending->addTags($connection, $tags);

        return true;
    }

    /**
     * @param  array<string>  $tags
     */
    private function invalidateTagsNow(array $tags): void
    {
        try {
            $this->cache->invalidate($tags);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Invalidate, $exception, ['tags' => $tags]);
        }
    }

    /**
     * Retires the generation now and again when the transaction ends, committed or not: a
     * transactional schema change only becomes visible at commit, while other databases apply
     * schema changes immediately and cannot roll them back.
     */
    public function schemaChanged(ConnectionInterface $connection): void
    {
        $this->retireGenerationNow(fn () => $this->cache->retireAfterSchemaChange());

        if (self::inTransaction($connection)) {
            $this->pending->requestRetire($connection);
        }
    }

    /**
     * @param  Closure(): void  $retire
     */
    private function retireGenerationNow(Closure $retire): void
    {
        try {
            $retire();
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Retire, $exception);
        }
    }

    private static function inTransaction(ConnectionInterface $connection): bool
    {
        return $connection->transactionLevel() > 0;
    }

    private function startCollector(): void
    {
        $this->collector = App::bound('lada.collector') ? App::make('lada.collector') : null;
        $this->collector?->startMeasuring();
    }

    /**
     * @param  array<string>  $tags
     * @param  array<string>|string  $hashes
     */
    private function stopCollector(Reflector $reflector, array $tags, string|array $hashes, string $action): void
    {
        if ($this->collector === null) {
            return;
        }

        $this->collector->endMeasuring(
            $action,
            (array) $hashes,
            $tags,
            $reflector->getSql(),
            $reflector->getParameters()
        );
    }
}
