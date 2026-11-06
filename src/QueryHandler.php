<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\FailedInvalidations;
use Spiritix\LadaCache\Support\FailureReporter;
use Spiritix\LadaCache\Support\ForeignKeyDependents;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\PendingInvalidations;
use Spiritix\LadaCache\Support\RawStatementEffect;
use Spiritix\LadaCache\Support\ReplicaReads;
use Throwable;

/**
 * Coordinates query caching and invalidation for Eloquent/DB queries.
 *
 * Responsibilities:
 * - Decide whether a query should be cached using `Manager`.
 * - Compute cache keys with `Hasher` and tags with `Tagger`.
 * - Look up, store and invalidate results via `Cache`.
 * - Defer invalidations of writes inside a transaction until the outermost commit.
 * - Extend invalidations to tables the database changes through foreign key actions.
 * - Keep invalidations Redis rejected and replay them before the cache is used again.
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
    private const int MAX_REPLAYED_INVALIDATION_SIZE = 1000;

    private readonly PendingInvalidations $pending;

    private readonly FailedInvalidations $failed;

    public function __construct(
        private readonly Cache $cache,
        ?FailedInvalidations $failed = null,
    ) {
        $this->pending = new PendingInvalidations;
        $this->failed = $failed ?? FailedInvalidations::processLocal();
    }

    /**
     * @param  string  $statementType  One of Reflector::QUERY_TYPE_*
     * @param  array<string, mixed>  $values  Values used by the grammar to compile the SQL (e.g., update sets)
     */
    public function invalidateQuery(QueryBuilder $builder, string $statementType, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $collector = $this->startCollector();
        $connection = $builder->getConnection();

        try {
            $reflector = new Reflector($builder, $statementType, $values);

            if (! (new Manager($reflector))->shouldInvalidate()) {
                return;
            }

            $tags = (new Tagger($reflector, $assignedRows))->getTags();
            $invalidation = Invalidation::of(
                $tags,
                $reflector->getDatabase(),
                (string) $connection->getName(),
                $reflector->getType() === Reflector::QUERY_TYPE_INSERT ? [] : $reflector->getTables(),
                ReplicaReads::lagSeconds($connection),
            );
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Invalidate, $exception, ['statement' => $statementType]);
            $this->invalidate($connection, Invalidation::everything());

            return;
        }

        $deferred = $this->invalidate($connection, $invalidation);
        $collector?->endMeasuring($deferred ? "Invalidation queued ({$statementType})" : "Invalidation ({$statementType})", [], $tags, $reflector->getSql(), $reflector->getParameters());
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

            $this->invalidateQuery($builder, Reflector::QUERY_TYPE_TRUNCATE);
        }
    }

    /**
     * Retires the generation now and again when the transaction ends, committed or not: a
     * transactional schema change only becomes visible at commit, while other databases apply
     * schema changes immediately and cannot roll them back.
     */
    public function schemaChanged(ConnectionInterface $connection): void
    {
        $this->invalidateNow(Invalidation::everything());

        if (self::inTransaction($connection)) {
            $this->pending->add($connection, Invalidation::everything());
        }
    }

    /**
     * Runs the builder's select through the cache. The query closure receives the compiled SQL,
     * so it is compiled once for both the cache key and the database.
     *
     * @param  Closure(string): array<mixed>  $runQuery
     * @return array<mixed>
     */
    public function cacheQuery(QueryBuilder $builder, Closure $runQuery): array
    {
        $collector = $this->startCollector();

        try {
            $reflector = new Reflector($builder);
            $sql = $reflector->getSql();
            $cacheable = (new Manager($reflector))->shouldCache();
            $key = $cacheable ? (new Hasher($reflector))->getHash() : '';
            $tags = $cacheable ? (new Tagger($reflector))->getTags() : [];
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Analyze, $exception);

            return $runQuery($builder->toSql());
        }

        if (! $cacheable || ! $this->replayFailedInvalidations()) {
            return $runQuery($sql);
        }

        $lookup = $this->lookup($key, $tags);

        if ($lookup instanceof CacheHit) {
            $collector?->endMeasuring('Hit', [$key], $tags, $sql, $reflector->getParameters());

            return $lookup->rows;
        }

        $rows = $runQuery($sql);

        if ($lookup instanceof CacheMiss) {
            $readFromReplica = ReplicaReads::readsFromReplica($builder->getConnection(), $builder->useWritePdo);
            $this->store($lookup, $rows, $readFromReplica ? $tags : []);
        }

        $collector?->endMeasuring($lookup instanceof CacheMiss ? 'Miss' : 'Bypass', [$key], $tags, $sql, $reflector->getParameters());

        return $rows;
    }

    public function transactionCommitted(ConnectionInterface $connection): void
    {
        if (self::inTransaction($connection)) {
            return;
        }

        $this->invalidateNow($this->pending->take($connection));
    }

    public function transactionRolledBack(ConnectionInterface $connection): void
    {
        if (self::inTransaction($connection)) {
            return;
        }

        if ($this->pending->take($connection)->retiresEverything) {
            $this->invalidateNow(Invalidation::everything());
        }
    }

    /**
     * @param  array<string>  $tags
     */
    private function lookup(string $key, array $tags): CacheHit|CacheMiss|null
    {
        try {
            return $this->cache->lookup($key, $tags);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Read, $exception, ['key' => $key]);

            return null;
        }
    }

    /**
     * @param  array<mixed>  $rows
     * @param  array<string>  $tagsReadFromReplica
     */
    private function store(CacheMiss $miss, array $rows, array $tagsReadFromReplica): void
    {
        try {
            $this->cache->store($miss, $rows, $tagsReadFromReplica);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Store, $exception, ['key' => $miss->key]);
        }
    }

    /**
     * Invalidates now, or at the outermost commit when the connection is inside a transaction.
     *
     * @return bool Whether the invalidation was deferred
     */
    private function invalidate(ConnectionInterface $connection, Invalidation $invalidation): bool
    {
        if (! self::inTransaction($connection)) {
            $this->invalidateNow($invalidation);

            return false;
        }

        $this->pending->add($connection, $invalidation);

        return true;
    }

    private function invalidateNow(Invalidation $invalidation): void
    {
        if ($invalidation->isEmpty()) {
            return;
        }

        if (! $this->replayFailedInvalidations()) {
            $this->failed->record($invalidation);

            return;
        }

        try {
            $this->apply($invalidation);
        } catch (Throwable $exception) {
            $this->failed->record($invalidation);
            FailureReporter::report(
                $invalidation->retiresEverything ? CacheOperation::Retire : CacheOperation::Invalidate,
                $exception,
                ['tags' => $invalidation->tags()],
            );
        }
    }

    /**
     * @return bool Whether nothing is pending anymore, so the cache can be trusted again
     */
    private function replayFailedInvalidations(): bool
    {
        if (! $this->failed->hasPending()) {
            return true;
        }

        try {
            $this->failed->replay(function (Invalidation $invalidation): void {
                $this->apply($invalidation->size() > self::MAX_REPLAYED_INVALIDATION_SIZE ? Invalidation::everything() : $invalidation);
            });

            return true;
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Replay, $exception);

            return false;
        }
    }

    private function apply(Invalidation $invalidation): void
    {
        if ($invalidation->retiresEverything) {
            $this->cache->retireAfterSchemaChange();

            return;
        }

        $result = $this->cache->invalidate($invalidation);
        $generation = $result['generation'];
        $unknown = $result['databasesWithUnknownDependents'];

        if ($generation === null || $unknown === []) {
            return;
        }

        $stored = [];
        foreach ($unknown as $database) {
            try {
                $dependents = ForeignKeyDependents::load(DB::connection($invalidation->connectionOf($database)));
            } catch (Throwable $exception) {
                FailureReporter::report(CacheOperation::Invalidate, $exception, ['database' => $database, 'reading' => 'foreign keys']);
                $this->cache->retireAfterSchemaChange();

                return;
            }

            if ($this->cache->storeDependents($generation, $database, $dependents)) {
                $stored[] = $database;
            }
        }

        if ($stored !== []) {
            $this->cache->invalidate($invalidation->onlyCascadesOf($stored));
        }
    }

    private static function inTransaction(ConnectionInterface $connection): bool
    {
        return $connection->transactionLevel() > 0;
    }

    private function startCollector(): ?CacheCollector
    {
        $collector = App::bound('lada.collector') ? App::make('lada.collector') : null;
        $collector?->startMeasuring();

        return $collector;
    }
}
