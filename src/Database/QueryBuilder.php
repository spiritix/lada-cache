<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
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
        // If locked, defer to base implementation (always hit DB).
        if ($this->lock !== null) {
            return parent::exists();
        }

        // Use a cached-select pathway by limiting to 1 row and checking non-emptiness.
        // This ensures existence checks benefit from caching without changing semantics.
        return $this->clone()->limit(1)->get(['*'])->isNotEmpty();
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
            return parent::runSelect();
        }

        // Laravel can defer query mutations until just before execution. Finalize
        // those callbacks before reflecting SQL for cache keys and tags.
        $this->applyBeforeQueryCallbacks();

        return $this->handler
            ->setBuilder($this)
            ->cacheQuery(function () {
                return $this->connection->select(
                    $this->toSql(),
                    $this->getBindings(),
                    ! $this->useWritePdo
                );
            });
    }

    /**
     * {@inheritDoc}
     *
     * @param  Closure|Builder|EloquentBuilder<*>|Relation<*, *, *>|string  $query
     */
    public function fromSub($query, $as)
    {
        return parent::fromSub($this->trackCompiledSubQuery($query), $as);
    }

    /** {@inheritDoc} */
    public function selectSub($query, $as)
    {
        return parent::selectSub($this->trackCompiledSubQuery($query), $as);
    }

    /** {@inheritDoc} */
    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        return parent::joinSub($this->trackCompiledSubQuery($query), $as, $first, $operator, $second, $type, $where);
    }

    /** {@inheritDoc} */
    public function joinLateral($query, string $as, string $type = 'inner')
    {
        return parent::joinLateral($this->trackCompiledSubQuery($query), $as, $type);
    }

    /** {@inheritDoc} */
    public function orderBy($column, $direction = 'asc')
    {
        if ($this->isQueryable($column)) {
            $column = $this->trackCompiledSubQuery($column);
        }

        return parent::orderBy($column, $direction);
    }

    /** {@inheritDoc} */
    public function insert(array $values)
    {
        $result = parent::insert($values);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function insertUsing(array $columns, $query)
    {
        $result = parent::insertUsing($columns, $query);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_INSERT, [], AssignedRows::unknown());

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $result = parent::insertOrIgnoreUsing($columns, $query);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_INSERT, [], AssignedRows::unknown());

        return $result;
    }

    /** {@inheritDoc} */
    public function updateFrom(array $values)
    {
        $result = parent::updateFrom($values);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_UPDATE, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = parent::insertGetId($values, $sequence);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy([$this->getPrimaryKeyName() => $id]));

        return $id;
    }

    /** {@inheritDoc} */
    public function update(array $values)
    {
        $count = parent::update($values);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_UPDATE, $values, $this->rowsAssignedBy($values));

        return $count;
    }

    /** {@inheritDoc} */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $result = parent::upsert($values, $uniqueBy, $update);
        // Treat UPSERT as an UPDATE-like invalidation to safely clear unspecific table tags.
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_UPDATE, is_array($values) ? (array) $values : []);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnore(array $values)
    {
        $result = parent::insertOrIgnore($values);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_INSERT, $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function updateOrInsert(array $attributes, callable|array $values = [])
    {
        $result = parent::updateOrInsert($attributes, $values);
        // May perform insert or update; invalidate conservatively as UPDATE.
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_UPDATE, $values ?: $attributes);

        return $result;
    }

    /** {@inheritDoc} */
    public function delete($id = null)
    {
        $count = parent::delete($id);
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_DELETE, [$this->getPrimaryKeyName() => $id]);

        return $count;
    }

    /** {@inheritDoc} */
    public function truncate()
    {
        parent::truncate();
        $this->invalidateUnlessBypassed(Reflector::QUERY_TYPE_TRUNCATE);
    }

    private function invalidateUnlessBypassed(string $queryType, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        if ($this->cacheBypassed) {
            return;
        }

        $this->handler->setBuilder($this)->invalidateQuery($queryType, $values, $assignedRows);
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
