<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\SqlAliasParser;
use Spiritix\LadaCache\Support\Write;

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

    final public function __construct(
        Connection $connection,
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

    public function getConnection(): Connection
    {
        return $this->connection instanceof Connection ? $this->connection : throw new LogicException('Lada Cache query builders run on database connections, got '.get_debug_type($this->connection));
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * The primary key column of a table the query names. Only the model's own table has a known
     * key; every other table is assumed to use the conventional key, the same one any builder
     * without a model assumes, so reads and writes of a table always agree on its row tags.
     */
    public function primaryKeyOf(string $table): string
    {
        return $this->model !== null && $this->model->getTable() === $table
            ? $this->model->getKeyName()
            : self::DEFAULT_PRIMARY_KEY_NAME;
    }

    private function targetTable(): ?string
    {
        return is_string($this->from) ? SqlAliasParser::stripAlias($this->from) : null;
    }

    private function primaryKeyOfTarget(): ?string
    {
        $table = $this->targetTable();

        return $table === null ? null : $this->primaryKeyOf($table);
    }

    /** {@inheritDoc} */
    public function newQuery()
    {
        return new static(
            $this->getConnection(),
            $this->handler,
            $this->grammar,
            $this->processor,
            $this->model
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  ExpressionContract|string  $table
     */
    protected function newJoinClause(Builder $parentQuery, $type, $table)
    {
        return new JoinClause($parentQuery, $type, $table);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<mixed>
     */
    protected function runSelect()
    {
        if ($this->lock !== null || $this->cacheBypassed || $this->fetchUsing !== []) {
            return $this->runStatement(fn () => parent::runSelect());
        }

        $this->applyBeforeQueryCallbacks();

        return $this->handler->cacheQuery($this, fn (string $sql): array => $this->runStatement(fn () => $this->connection->select(
            $sql,
            $this->getBindings(),
            ! $this->useWritePdo
        )));
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values)
    {
        $result = $this->runStatement(fn () => parent::insert($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  list<string>  $columns
     */
    public function insertUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), [], AssignedRows::unknown());

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  list<string>  $columns
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $result = $this->runStatement(fn () => $this->withoutTrackingCompiledSubQueries(fn () => parent::insertOrIgnoreUsing($columns, $query)));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), [], AssignedRows::unknown());

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values)
    {
        $result = $this->runStatement(fn () => parent::updateFrom($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(array_keys($values))->affecting($result), $values, $this->rowsReassignedBy($values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = $this->runStatement(fn () => parent::insertGetId($values, $sequence));
        $primaryKey = $this->primaryKeyOfTarget();
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, $primaryKey === null ? AssignedRows::unknown() : $this->rowsAssignedBy([$primaryKey => $id]));

        return $id;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        $count = $this->runStatement(fn () => parent::update($values));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(array_keys($values))->affecting($count), $values, $this->rowsReassignedBy($values));

        return $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        $result = $this->runStatement(fn () => parent::upsert($values, $uniqueBy, $update));
        $this->invalidate(Reflector::QUERY_TYPE_UPDATE, Write::update(self::upsertedColumns($values, $update))->affecting($result), $values);

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnore($values));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $values
     * @return Collection<int, \stdClass>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        $result = $this->runStatement(fn () => parent::insertOrIgnoreReturning($values, $returning, $uniqueBy));
        $this->invalidate(Reflector::QUERY_TYPE_INSERT, Write::insert(), $values, $this->rowsAssignedBy($values));

        return $result;
    }

    /** {@inheritDoc} */
    public function delete($id = null)
    {
        $count = $this->runStatement(fn () => parent::delete($id));
        $this->invalidate(Reflector::QUERY_TYPE_DELETE, Write::delete()->affecting($count));

        return $count;
    }

    /** {@inheritDoc} */
    public function truncate()
    {
        $this->runStatement(fn () => parent::truncate());
        $this->invalidate(Reflector::QUERY_TYPE_TRUNCATE, Write::truncate());
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

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function invalidate(string $queryType, Write $write, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $this->handler->invalidateQuery($this, $queryType, $write, $values, $assignedRows);
    }

    /**
     * The columns an upsert assigns to existing rows, resolved as `Builder::upsert()` does.
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int|string, mixed>|null  $update
     * @return list<int|string>
     */
    private static function upsertedColumns(array $values, ?array $update): array
    {
        if ($update === null) {
            $first = reset($values);

            return array_keys(is_array($first) ? $first : $values);
        }

        $columns = [];
        foreach ($update as $key => $value) {
            $columns[] = is_int($key) ? (is_string($value) ? $value : throw new InvalidArgumentException('Upsert update columns must be names, got '.get_debug_type($value))) : $key;
        }

        return $columns;
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function rowsReassignedBy(array $values): AssignedRows
    {
        $primaryKey = $this->primaryKeyOfTarget();

        if ($primaryKey === null) {
            return AssignedRows::unknown();
        }

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
        $table = $this->targetTable();

        if ($table === null) {
            return AssignedRows::unknown();
        }

        $primaryKey = $this->primaryKeyOf($table);

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

        return AssignedRows::of($table, $ids, $complete);
    }
}
