<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\SqlAliasParser;

/**
 * LadaCache Query Builder for Laravel 12+
 *
 * Extends the base query builder to provide automatic caching and invalidation.
 */
class QueryBuilder extends Builder implements HasCompiledSubQueries
{
    use TracksCompiledSubQueries;

    public const string DEFAULT_PRIMARY_KEY_NAME = 'id';

    private readonly QueryHandler $handler;

    private readonly ?Model $model;

    private bool $cacheBypassed = false;

    public function __construct(
        ConnectionInterface $connection,
        QueryHandler $handler,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        ?Model $model = null
    ) {
        parent::__construct($connection, $grammar, $processor);
        $this->handler = $handler;
        $this->model = $model;
    }

    /**
     * Bypass Lada Cache for this query (skips read caching and write invalidation).
     */
    public function withoutCache(): static
    {
        $this->cacheBypassed = true;

        return $this;
    }

    public function isCacheBypassed(): bool
    {
        return $this->cacheBypassed;
    }

    /** {@inheritDoc} */
    public function exists()
    {
        if ($this->lock !== null) {
            return parent::exists();
        }

        return $this->clone()->limit(1)->get([new Expression('1 as lada_exists')])->isNotEmpty();
    }

    public function getHandler(): QueryHandler
    {
        return $this->handler;
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    public function getPrimaryKeyName(): string
    {
        return $this->model?->getKeyName() ?? self::DEFAULT_PRIMARY_KEY_NAME;
    }

    /** {@inheritDoc} */
    public function newQuery()
    {
        return new static(
            $this->connection,
            $this->handler,
            $this->grammar,
            $this->processor,
            $this->model
        );
    }

    /** {@inheritDoc} */
    protected function newJoinClause(Builder $parentQuery, $type, $table)
    {
        return new JoinClause($parentQuery, $type, $table);
    }

    /** {@inheritDoc} */
    protected function runSelect()
    {
        // Do not cache queries that use pessimistic locks (lockForUpdate/sharedLock).
        // Laravel stores lock intent in $this->lock; when present we should always hit the DB.
        // Also bypass when the caller opted out via withoutCache().
        if ($this->lock !== null || $this->cacheBypassed) {
            return $this->runStatement(fn () => parent::runSelect());
        }

        // Laravel can defer query mutations until just before execution. Finalize
        // those callbacks before reflecting SQL for cache keys and tags.
        $this->applyBeforeQueryCallbacks();

        return $this->handler->cacheQuery($this, fn (string $sql): array => $this->runStatement(fn () => $this->connection->select(
            $sql,
            $this->getBindings(),
            ! $this->useWritePdo
        )));
    }

    /** {@inheritDoc} */
    public function insert(array $values)
    {
        $result = $this->runStatement(fn () => parent::insert($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function insertUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, [], AssignedRows::unknown());

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertOrIgnoreUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, [], AssignedRows::unknown());

        return $result;
    }

    /** {@inheritDoc} */
    public function updateFrom(array $values)
    {
        $result = $this->runStatement(fn () => parent::updateFrom($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, $values, $this->rowsReassignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = $this->runStatement(fn () => parent::insertGetId($values, $sequence));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy([$this->getPrimaryKeyName() => $id]));

        return $id;
    }

    /** {@inheritDoc} */
    public function update(array $values)
    {
        $count = $this->runStatement(fn () => parent::update($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, $values, $this->rowsReassignedBy($values));

        return $count;
    }

    /** {@inheritDoc} */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $result = $this->runStatement(fn () => parent::upsert($values, $uniqueBy, $update));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, $values);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnore(array $values)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnore($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnoreReturning($values, $returning, $uniqueBy));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function delete($id = null)
    {
        $count = $this->runStatement(fn () => parent::delete($id));
        $this->invalidate(Reflector::QUERY_TYPE_DELETE, [$this->getPrimaryKeyName() => $id]);

        return $count;
    }

    /** {@inheritDoc} */
    public function truncate()
    {
        $this->runStatement(fn () => parent::truncate());
        $this->invalidate(Reflector::QUERY_TYPE_TRUNCATE);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $statement
     * @return TResult
     */
    private function runStatement(Closure $statement): mixed
    {
        return $this->connection instanceof RunsBuilderStatements
            ? $this->connection->runBuilderStatement($statement)
            : $statement();
    }

    private function invalidate(string $queryType, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $this->handler->invalidateQuery($this, $queryType, $values, $assignedRows);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function rowsReassignedBy(array $values): AssignedRows
    {
        $primaryKey = $this->getPrimaryKeyName();
        $assignsKey = false;

        foreach (array_keys($values) as $column) {
            if ($column === $primaryKey) {
                $assignsKey = true;
            } elseif (is_string($column) && str_ends_with($column, '.'.$primaryKey)) {
                return AssignedRows::unknown();
            }
        }

        return $assignsKey ? $this->rowsAssignedBy($values) : new AssignedRows;
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function rowsAssignedBy(array $values): AssignedRows
    {
        if (! is_string($this->from)) {
            return AssignedRows::unknown();
        }

        $primaryKey = $this->getPrimaryKeyName();
        $ids = [];
        $complete = true;

        foreach (is_array(reset($values)) ? $values : [$values] as $row) {
            $id = is_array($row) ? ($row[$primaryKey] ?? null) : null;

            if ((is_int($id) || is_string($id)) && $id !== '') {
                $ids[] = $id;
            } else {
                $complete = false;
            }
        }

        return AssignedRows::of(SqlAliasParser::stripAlias($this->from), $ids, $complete);
    }
}
