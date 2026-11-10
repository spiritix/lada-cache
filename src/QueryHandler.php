<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\CascadeRead;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\FailedInvalidations;
use Spiritix\LadaCache\Support\FailureReporter;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\PendingInvalidations;
use Spiritix\LadaCache\Support\RawStatementEffect;
use Spiritix\LadaCache\Support\RedisCooldown;
use Spiritix\LadaCache\Support\ReplicaReads;
use Spiritix\LadaCache\Support\RowKeys;
use Spiritix\LadaCache\Support\Write;
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
     * @param  Write  $write  What the statement did to the rows, deciding what the database changed along with them
     * @param  array<int|string, mixed>  $values  Values used by the grammar to compile the SQL (e.g., update sets)
     */
    public function invalidateQuery(QueryBuilder $builder, string $statementType, Write $write, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $collector = $this->startCollector();
        $connection = $builder->getConnection();

        try {
            $reflector = new Reflector($builder, $statementType, $values);

            if (! (new Manager($reflector))->shouldInvalidate()) {
                return;
            }

            $tagger = new Tagger($reflector, $assignedRows);
            $tags = $tagger->getTags();
            $invalidation = Invalidation::ofWrite($tags, $tagger->tableWideTags(), ReplicaReads::lagSeconds($connection))->withChangesOf(
                $reflector->getDatabase(),
                $connection->getName() ?? throw new LogicException('Lada Cache invalidates writes on named connections only'),
                self::narrowedToDeletedKeys($write, $builder, $reflector),
                $reflector->getTables(),
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

        $this->invalidateTables($connection, $effect->tables, $effect->write);
    }

    /**
     * Invalidates every cached read of the tables, and of the tables the database changes along
     * with them, as the write to each table would.
     *
     * @param  list<string>  $tables
     */
    public function invalidateTables(Connection $connection, array $tables, Write $write): void
    {
        foreach ($tables as $table) {
            $builder = $connection->query()->from($table);

            if (! $builder instanceof QueryBuilder) {
                return;
            }

            $this->invalidateQuery($builder, Reflector::QUERY_TYPE_TRUNCATE, $write);
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
            $tagger = new Tagger($reflector);
            $key = $cacheable ? (new Hasher($reflector))->getHash() : '';
            $tags = $cacheable ? $tagger->getTags() : [];
            $cascadeReads = $cacheable ? $tagger->cascadeReads() : [];
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Analyze, $exception);

            return $runQuery($builder->toSql());
        }

        if (! $cacheable || ! $this->replayFailedInvalidations()) {
            return $runQuery($sql);
        }

        $lookup = $this->lookup($key, $tags, $cascadeReads);

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
     * @param  list<CascadeRead>  $cascadeReads
     */
    private function lookup(string $key, array $tags, array $cascadeReads): CacheHit|CacheMiss|null
    {
        try {
            return $this->cache->lookup($key, $tags, $cascadeReads);
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

        try {
            $this->replayPendingInvalidations(RedisCooldown::Respect);
            $this->apply($invalidation, RedisCooldown::Respect);
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
     * Applies invalidations Redis rejected earlier, if any. Also run when a request or worker
     * ends, ignoring the cooldown the rejection started, so invalidations kept only in memory
     * are not lost with the process when Redis is back.
     *
     * @return bool Whether nothing is pending anymore, so the cache can be trusted again
     */
    public function replayFailedInvalidations(RedisCooldown $cooldown = RedisCooldown::Respect): bool
    {
        try {
            $this->replayPendingInvalidations($cooldown);

            return true;
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Replay, $exception);

            return false;
        }
    }

    private function replayPendingInvalidations(RedisCooldown $cooldown): void
    {
        if (! $this->failed->hasPending()) {
            return;
        }

        $this->failed->replay(fn (Invalidation $invalidation) => $this->apply($invalidation, $cooldown));
    }

    private function apply(Invalidation $invalidation, RedisCooldown $cooldown): void
    {
        if ($invalidation->retiresEverything) {
            $this->cache->retireAfterSchemaChange();

            return;
        }

        $result = $this->cache->invalidate($invalidation, $cooldown);
        $generation = $result['generation'];
        $unknown = $result['databasesWithUnknownDependents'];

        if ($generation === null || $unknown === []) {
            return;
        }

        $stored = [];
        foreach ($unknown as $database) {
            try {
                $dependents = DependentTables::load(DB::connection($invalidation->connectionOf($database)));
            } catch (Throwable $exception) {
                FailureReporter::report(CacheOperation::Invalidate, $exception, ['database' => $database, 'reading' => 'dependent tables']);
                $this->cache->retireAfterSchemaChange();

                return;
            }

            if ($this->cache->storeDependents($generation, $database, $dependents, $cooldown)) {
                $stored[] = $database;
            }
        }

        if ($stored !== []) {
            $this->cache->invalidate($invalidation->onlyCascadesOf($stored), $cooldown);
        }
    }

    /**
     * A delete of one table pinned to primary key values changes, through foreign keys referencing
     * that key, only the child rows referencing those values.
     */
    private static function narrowedToDeletedKeys(Write $write, QueryBuilder $builder, Reflector $reflector): Write
    {
        $tables = $reflector->getTables();

        if (count($tables) !== 1 || $reflector->getType() !== Reflector::QUERY_TYPE_DELETE) {
            return $write;
        }

        $table = $tables[0];
        $rows = $reflector->getRows()[$table] ?? null;
        $keys = $rows === null ? null : RowKeys::columnValues($rows);

        return $keys === null ? $write : $write->deletingKeys($table, $builder->primaryKeyOf($table), $keys);
    }

    private static function inTransaction(ConnectionInterface $connection): bool
    {
        return $connection->transactionLevel() > 0;
    }

    private function startCollector(): ?CacheCollector
    {
        $collector = Services::collector();
        $collector?->startMeasuring();

        return $collector;
    }
}
